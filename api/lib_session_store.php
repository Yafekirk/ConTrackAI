<?php
declare(strict_types=1);

/**
 * PHP session storage in Supabase (public.app_sessions) so sign-ins survive redeploys; container
 * files are wiped on every deploy. Rows are keyed by the SHA-256 of the session id, never the raw id.
 * If the table is missing or Supabase is unreachable, the request falls back to PHP's file storage
 * instead of signing the user out.
 */
final class ContrackSessionStore extends SessionHandler implements SessionUpdateTimestampHandlerInterface
{
    /** Only push the expiry forward once this many seconds have passed, to avoid a write per request. */
    private const TOUCH_INTERVAL = 15 * 60;

    private bool $useFiles = false;
    /** @var array<string, array{data: string, expires: int}|null> Rows loaded this request, keyed by raw id. */
    private array $rows = [];

    public function __construct(private int $lifetime)
    {
    }

    private static function key(string $id): string
    {
        return hash("sha256", $id);
    }

    private function fail(array $result): void
    {
        $this->useFiles = true;
        error_log("[contrack] session store unavailable, using file sessions: " . substr((string)($result["raw"] ?? $result["error"] ?? ""), 0, 300));
    }

    /** @return array{data: string, expires: int}|null|false Row, null when absent, false when the store failed. */
    private function load(string $id)
    {
        if (array_key_exists($id, $this->rows)) {
            return $this->rows[$id];
        }
        $key = self::key($id);
        $result = supabase_request("GET", "app_sessions?id=eq.{$key}&select=data,expires_at&limit=1");
        if (!$result["ok"] || !is_array($result["data"])) {
            $this->fail($result);
            return false;
        }
        $row = $result["data"][0] ?? null;
        $expires = is_array($row) ? (int)strtotime((string)($row["expires_at"] ?? "")) : 0;
        $this->rows[$id] = ($row !== null && $expires > time())
            ? ["data" => (string)($row["data"] ?? ""), "expires" => $expires]
            : null;
        return $this->rows[$id];
    }

    private function save(string $id, string $data): bool
    {
        if ($data === "" && ($this->rows[$id] ?? null) === null) {
            // Visitors who never sign in get no row.
            return true;
        }
        $key = self::key($id);
        $expires = time() + $this->lifetime;
        $fields = [
            "data" => $data,
            "expires_at" => gmdate("c", $expires),
            "updated_at" => gmdate("c"),
        ];
        $known = $this->rows[$id] ?? null;
        $result = $known !== null
            ? supabase_request("PATCH", "app_sessions?id=eq.{$key}", $fields)
            : supabase_request("POST", "app_sessions", [array_merge(["id" => $key], $fields)]);
        if ($known === null && !$result["ok"] && (int)($result["status"] ?? 0) === 409) {
            $result = supabase_request("PATCH", "app_sessions?id=eq.{$key}", $fields);
        }
        if (!$result["ok"]) {
            $this->fail($result);
            return parent::write($id, $data);
        }
        $this->rows[$id] = ["data" => $data, "expires" => $expires];
        return true;
    }

    public function read(string $id): string|false
    {
        if (!$this->useFiles) {
            $row = $this->load($id);
            if ($row !== false) {
                return $row["data"] ?? "";
            }
        }
        return parent::read($id);
    }

    public function write(string $id, string $data): bool
    {
        if ($this->useFiles) {
            return parent::write($id, $data);
        }
        return $this->save($id, $data);
    }

    public function destroy(string $id): bool
    {
        unset($this->rows[$id]);
        if (!$this->useFiles) {
            supabase_request("DELETE", "app_sessions?id=eq." . self::key($id));
        }
        return parent::destroy($id);
    }

    public function gc(int $max_lifetime): int|false
    {
        if (!$this->useFiles) {
            supabase_request("DELETE", "app_sessions?expires_at=lt." . rawurlencode(gmdate("c")));
        }
        return parent::gc($max_lifetime);
    }

    public function validateId(string $id): bool
    {
        if ($this->useFiles) {
            return true;
        }
        $row = $this->load($id);
        // A store outage must not reject every cookie, which would sign everyone out.
        return $row === false || $row !== null;
    }

    public function updateTimestamp(string $id, string $data): bool
    {
        if ($this->useFiles) {
            return true;
        }
        $row = $this->rows[$id] ?? null;
        if ($row !== null && ($row["expires"] - time()) > ($this->lifetime - self::TOUCH_INTERVAL)) {
            return true;
        }
        return $this->save($id, $data);
    }
}
