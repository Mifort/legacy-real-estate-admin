<?php
namespace Tests;

// HTTP-клиент с куками и CSRF-токеном (одна «сессия браузера» на экземпляр)
class Http
{
    private string $base;
    private string $cookieJar;
    public ?string $csrf = null;

    public function __construct()
    {
        $this->base = rtrim(getenv('TEST_BASE_URL') ?: 'http://localhost', '/');
        $this->cookieJar = tempnam(sys_get_temp_dir(), 'ck');
    }

    public function __destruct()
    {
        @unlink($this->cookieJar);
    }

    private function exec(array $opts): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, $opts + [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIEJAR => $this->cookieJar,
            CURLOPT_COOKIEFILE => $this->cookieJar,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 30,
        ]);
        $body = curl_exec($ch);
        if ($body === false) {
            throw new \RuntimeException('curl: ' . curl_error($ch));
        }
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['status' => $code, 'body' => $body];
    }

    public function get(string $path): array
    {
        return $this->exec([CURLOPT_URL => $this->base . $path]);
    }

    /** POST формы. $headers — ассоц. массив, например ['X-CSRF-Token' => '...'] */
    public function post(string $path, array $fields, array $headers = []): array
    {
        $hdr = [];
        foreach ($headers as $k => $v) {
            $hdr[] = "$k: $v";
        }
        return $this->exec([
            CURLOPT_URL => $this->base . $path,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($fields),
            CURLOPT_HTTPHEADER => $hdr,
        ]);
    }

    /** POST с CSRF-токеном текущей сессии в заголовке */
    public function postCsrf(string $path, array $fields): array
    {
        return $this->post($path, $fields, ['X-CSRF-Token' => (string) $this->csrf]);
    }

    /** Загрузка файла (multipart) с CSRF-токеном */
    public function upload(string $path, string $filePath, array $fields): array
    {
        $post = $fields;
        $post['files[]'] = new \CURLFile($filePath, 'image/jpeg', basename($filePath));
        return $this->exec([
            CURLOPT_URL => $this->base . $path,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $post,
            CURLOPT_HTTPHEADER => ['X-CSRF-Token: ' . (string) $this->csrf],
        ]);
    }

    // Вход: берёт csrf со страницы логина, отправляет форму, запоминает meta-токен
    public function login(string $login, string $password): bool
    {
        $page = $this->get('/admin/login/')['body'];
        preg_match('/csrf_token" value="([a-f0-9]+)"/', $page, $m);
        $this->post('/admin/login/', [
            'login' => $login,
            'password' => $password,
            'csrf_token' => $m[1] ?? '',
        ]);
        $home = $this->get('/admin/');
        if (preg_match('/csrf-token" content="([a-f0-9]+)"/', $home['body'], $mm)) {
            $this->csrf = $mm[1];
            return true;
        }
        return false;
    }

    public function cookieJarPath(): string
    {
        return $this->cookieJar;
    }

    public function loginToken(): ?string
    {
        $page = $this->get('/admin/login/')['body'];
        return preg_match('/csrf_token" value="([a-f0-9]+)"/', $page, $m) ? $m[1] : null;
    }
}
