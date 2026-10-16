<?php

declare(strict_types=1);

namespace GlpiPlugin\Assetsync20agent;

final class AgentApiResponse extends \RuntimeException
{
    public function __construct(public mixed $body, public int $status) { parent::__construct('Agent API response'); }
}

/** Narrow plugin routes using GLPI's native authentication lifecycle. */
final class AgentApi extends \Glpi\Api\APIRest
{
    private string $body = '';
    private string $connection = '';
    private int $user = 0;
    private bool $authenticated = false;

    public static function validatePeer(array $peer): void
    {
        global $CFG_GLPI;
        $user = new \User();
        $profile = new \Profile();
        $grant = new \Profile_User();
        $client = new \APIClient();
        $now = date('Y-m-d H:i:s');
        $id = (int) ($peer['agent_peer_user'] ?? 0);
        $profileId = (int) ($peer['agent_peer_profile'] ?? 0);
        $entity = (string) ($peer['agent_peer_entity'] ?? '');
        if ($id <= 0 || $profileId <= 0 || !ctype_digit($entity)
            || !$user->getFromDB($id) || !$user->fields['is_active'] || $user->fields['is_deleted']
            || (!empty($user->fields['begin_date']) && $user->fields['begin_date'] >= $now)
            || (!empty($user->fields['end_date']) && $user->fields['end_date'] <= $now)
            || !$client->getFromDB((int) ($peer['agent_peer_api_client'] ?? 0)) || !$client->fields['is_active']
            || empty($client->fields['app_token'])
            || !$profile->getFromDB($profileId) || ($profile->fields['interface'] ?? '') !== 'central'
            || !$grant->getFromDBByCrit(['users_id' => $id, 'profiles_id' => $profileId,
                'entities_id' => (int) $entity, 'is_recursive' => 0])) {
            throw new \UnexpectedValueException('Agent API peer permission unavailable.');
        }
        if (!array_key_exists('2fa', $user->fields) || !array_key_exists('2fa_unenforced', $user->fields)
            || !in_array($user->fields['2fa_unenforced'], [0, 1, '0', '1'], true)
            || !in_array($CFG_GLPI['2fa_enforced'] ?? null, [0, 1, '0', '1'], true)
            || !class_exists(\Glpi\Security\TOTPManager::class)) {
            throw new \UnexpectedValueException('Agent API MFA policy unavailable.');
        }
        $mfa = new \Glpi\Security\TOTPManager();
        if ($mfa->is2FAEnabled($id) || $mfa->get2FAEnforcement($id) !== \Glpi\Security\TOTPManager::ENFORCEMENT_OPTIONAL
            || ($user->fields['2fa'] !== null && $user->fields['2fa'] !== '')) {
            throw new \UnexpectedValueException('Agent API requires optional, unconfigured MFA.');
        }
    }

    public function assertPeer(array $peer): void
    {
        if (!$this->authenticated || ($peer['agent_auth'] ?? '') !== 'glpi_session'
            || $this->user !== (int) ($peer['agent_peer_user'] ?? 0)
            || (int) $this->apiclients_id !== (int) ($peer['agent_peer_api_client'] ?? 0)
            || $this->connection !== (string) ($peer['connection_id'] ?? $peer['id'] ?? '')) {
            $this->returnResponse(['error' => 'Agent API identity mismatch'], 401);
        }
        try { self::validatePeer($peer); }
        catch (\UnexpectedValueException) { $this->returnResponse(['error' => 'Agent API permission revoked'], 401); }
    }

    private function activatePeer(array $peer): void
    {
        $this->assertPeer($peer);
        \Session::initEntityProfiles($this->user);
        \Session::changeProfile((int) $peer['agent_peer_profile']);
        $entity = (int) $peer['agent_peer_entity'];
        if (!\Session::changeActiveEntities($entity, false)
            || (int) ($_SESSION['glpiactiveprofile']['id'] ?? 0) !== (int) $peer['agent_peer_profile']
            || (int) ($_SESSION['glpiactive_entity'] ?? -1) !== $entity
            || !empty($_SESSION['glpiactive_entity_recursive'])
            || array_values(array_map('intval', $_SESSION['glpiactiveentities'] ?? [])) !== [$entity]
            || \Session::isCron() || \Session::isInventory() || \Session::isRightChecksDisabled()) {
            $this->returnResponse(['error' => 'Agent API scope activation failed'], 401);
        }
        \Glpi\Search\SearchOption::clearSearchOptionCache();
    }

    public function call()
    {
        global $CFG_GLPI, $TRANSLATE;
        $savedConfig = $CFG_GLPI;
        $savedTranslation = $TRANSLATE ?? null;
        $savedRequest = $_REQUEST;
        $savedTimezone = date_default_timezone_get();
        $savedSession = $_SESSION ?? [];
        $savedId = session_id();
        $options = [];
        $owned = false;
        $minted = false;
        $keep = false;
        $response = ['error' => 'Agent API unavailable'];
        $status = 503;
        try {
            // Never adopt, close or destroy an unexpected browser session.
            if (session_status() === PHP_SESSION_ACTIVE || $savedId !== '' || !empty($_SERVER['HTTP_COOKIE'])
                || !empty($_COOKIE)) {
                $this->returnResponse(['error' => 'Stateless request required'], 401);
            }
            if (!in_array(strtolower((string) ($_SERVER['HTTPS'] ?? '')), ['on', '1'], true)) {
                $this->returnResponse(['error' => 'HTTPS required'], 403);
            }
            if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
                $this->returnResponse(['error' => 'POST required'], 405);
            }
            if (!empty($_SERVER['QUERY_STRING']) || !empty($_GET) || !empty($_POST) || !empty($_FILES)
                || !empty($_SERVER['PATH_INFO']) || isset($_SERVER['PHP_AUTH_USER']) || isset($_SERVER['PHP_AUTH_PW'])
                || strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''))[0])) !== 'application/json'
                || (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 65536) {
                $this->returnResponse(['error' => 'Invalid Agent API request'], 400);
            }
            $this->body = (string) file_get_contents('php://input', false, null, 0, 65537);
            $payload = json_decode($this->body, true, 32, JSON_THROW_ON_ERROR);
            if (strlen($this->body) > 65536 || !is_array($payload) || !str_starts_with(ltrim($this->body), '{')
                || array_intersect(array_keys($payload), ['app_token', 'session_token', 'user_token', 'login', 'password',
                    'auth', 'session_write', 'debug', 'get_full_session']) !== []) {
                $this->returnResponse(['error' => 'Invalid Agent API body'], 400);
            }
            $operation = (string) ($_SERVER['HTTP_X_ASSETSYNC_OPERATION'] ?? '');
            if (!in_array($operation, ['initSession', 'killSession', 'wake'], true)
                || (in_array($operation, ['initSession', 'killSession'], true) && $payload !== [])) {
                $this->returnResponse(['error' => 'Unknown Agent API operation'], 400);
            }
            $this->connection = (string) ($_SERVER['HTTP_X_ASSETSYNC_CONNECTION'] ?? '');
            if ($this->connection === '' || strlen($this->connection) > 255 || preg_match('/[\x00-\x20\x7f]/', $this->connection)) {
                $this->returnResponse(['error' => 'Invalid connection binding'], 401);
            }
            foreach (['HTTP_APP_TOKEN', 'HTTP_SESSION_TOKEN', 'HTTP_AUTHORIZATION'] as $header) {
                $value = (string) ($_SERVER[$header] ?? '');
                if (strlen($value) > 1024 || preg_match('/[\x00-\x20\x7f]/', $header === 'HTTP_AUTHORIZATION'
                    ? preg_replace('/^user_token /', '', $value) : $value)) {
                    $this->returnResponse(['error' => 'Invalid credential header'], 401);
                }
            }
            if (empty($_SERVER['HTTP_APP_TOKEN'])
                || ($operation === 'initSession' ? (!empty($_SERVER['HTTP_SESSION_TOKEN'])
                    || !preg_match('/^user_token [^\s]{1,1024}$/D', (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '')))
                    : (empty($_SERVER['HTTP_SESSION_TOKEN']) || !empty($_SERVER['HTTP_AUTHORIZATION'])))) {
                $this->returnResponse(['error' => 'Credential headers required'], 401);
            }
            $peer = $this->peer();
            self::validatePeer($peer); // MFA is checked before core can mint a session.
            foreach (['session.use_cookies', 'session.use_only_cookies', 'session.use_trans_sid'] as $option) {
                $previous = ini_get($option);
                if ($previous === false) { throw new \RuntimeException('Session isolation unavailable.'); }
                $options[$option] = $previous;
                if ((ini_set($option, '0') === false && $previous !== '0') || ini_get($option) !== '0') {
                    throw new \RuntimeException('Session isolation unavailable.');
                }
            }
            $this->request_uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
            $this->verb = 'POST';
            $this->url_elements = [$operation];
            $this->parseIncomingParams();
            if ($operation === 'initSession') {
                $owned = true;
                $minted = true;
                \Session::start();
            } else {
                // Parent decodes the native opaque token. Validate decoded session IDs before restoration.
                if (!is_string($this->parameters['session_token'] ?? null)
                    || !preg_match('/^[a-zA-Z0-9,-]{16,256}$/D', $this->parameters['session_token'])) {
                    $this->returnResponse(['error' => 'Invalid native session'], 401);
                }
                $owned = true;
                $this->retrieveSession();
            }
            $this->initApi(); // Native enabled/API-client/IP admission on every request.
            $pinnedClient = (int) $peer['agent_peer_api_client'];
            if (!isset($this->app_tokens[$pinnedClient]) || !hash_equals($this->app_tokens[$pinnedClient],
                (string) $this->parameters['app_token'])) {
                $this->returnResponse(['error' => 'API client mismatch'], 401);
            }
            if ($operation === 'initSession') {
                $result = $this->initSession($this->parameters);
                $this->user = (int) \Session::getLoginUserID();
                $this->authenticated = true;
                $this->assertPeer($this->peer());
                \Session::start();
                $this->activatePeer($this->peer());
                $_SESSION['assetsync20agent_native_peer'] = [$this->connection, $this->user, (int) $this->apiclients_id, AgentSession::fingerprint($this->peer())];
                session_write_close();
                $keep = true;
                $this->returnResponse($result);
            }
            $this->initEndpoint(true, $operation); // Core releases the session lock before plugin work.
            $this->user = (int) \Session::getLoginUserID();
            $this->authenticated = true;
            $this->assertPeer($this->peer());
            if (($_SESSION['assetsync20agent_native_peer'] ?? null) !== [$this->connection, $this->user, (int) $this->apiclients_id, AgentSession::fingerprint($this->peer())]) {
                $this->returnResponse(['error' => 'Session belongs to a different peer'], 401);
            }
            if ($operation === 'killSession') {
                $this->returnResponse($this->killSession());
            }
            $this->activatePeer($this->peer());
            try { $result = $this->dispatch($operation, $payload); }
            catch (\UnexpectedValueException) {
                $this->returnResponse(['error' => 'Agent operation binding or evidence conflict'], 409);
            }
            $code = $result['status'];
            unset($result['status']);
            $this->returnResponse($result, $code);
        } catch (AgentApiResponse $result) {
            $response = $result->body;
            $status = $result->status;
        } catch (\JsonException | \InvalidArgumentException) {
            $response = ['error' => 'Invalid Agent API request']; $status = 400;
        } catch (\UnexpectedValueException) {
            $response = ['error' => 'Agent API authorization or binding rejected']; $status = 401;
        } catch (\Throwable) {
            $response = ['error' => 'Agent API unavailable']; $status = 503;
        } finally {
            try {
                if ($owned) {
                    // Only a session minted by this init request is destroyed on failure.
                    if ($minted && !$keep) {
                        if (session_status() !== PHP_SESSION_ACTIVE && session_id() !== '') { \Session::start(); }
                        if (session_status() === PHP_SESSION_ACTIVE) { session_destroy(); }
                    } elseif (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
                }
            } catch (\Throwable) {
                $response = ['error' => 'Agent API cleanup unavailable']; $status = 503;
            } finally {
                try {
                    if ($owned) {
                        if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
                        session_id($savedId);
                    }
                } catch (\Throwable) {
                    $response = ['error' => 'Agent API session restoration unavailable']; $status = 503;
                } finally {
                    foreach ($options as $option => $value) {
                        try { ini_set($option, $value); }
                        catch (\Throwable) { $response = ['error' => 'Agent API session policy restoration unavailable']; $status = 503; }
                    }
                    $_SESSION = $savedSession;
                    $_REQUEST = $savedRequest;
                    $CFG_GLPI = $savedConfig;
                    try {
                        if ($owned) {
                            \Session::loadLanguage();
                            \Glpi\Search\SearchOption::clearSearchOptionCache();
                        }
                    } catch (\Throwable) {
                        $response = ['error' => 'Agent API context restoration unavailable']; $status = 503;
                    } finally {
                        $_SESSION = $savedSession;
                        $_REQUEST = $savedRequest;
                        $CFG_GLPI = $savedConfig;
                        $TRANSLATE = $savedTranslation;
                        date_default_timezone_set($savedTimezone);
                    }
                }
            }
        }
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        http_response_code($status);
        echo json_encode($response, JSON_THROW_ON_ERROR);
    }

    protected function getHttpBody() { return $this->body; }

    public function returnResponse($response, $httpcode = 200, $additionalheaders = [])
    {
        throw new AgentApiResponse($response, (int) $httpcode);
    }

    private function peer(): array
    {
        $peer = Settings::load();
        if (($peer['mode'] ?? '') !== 'agent' || ($peer['agent_auth'] ?? '') !== 'glpi_session') {
            throw new \UnexpectedValueException('Native agent connection unavailable.');
        }
        return $peer;
    }

    private function dispatch(string $operation, array $payload): array
    {
        if (strlen($this->body) > 1024) { throw new \InvalidArgumentException('Wake body exceeds limit.'); }
        try { return Wake::receive($payload, '', $this); }
        catch (\RuntimeException $error) {
            if ($error->getCode() === 429) { $this->returnResponse(['error' => 'Wake rate limited'], 429); }
            throw $error;
        }
    }
}

