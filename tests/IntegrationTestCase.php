<?php
namespace Tests;

use PHPUnit\Framework\TestCase;

abstract class IntegrationTestCase extends TestCase
{
    protected function admin(): Http
    {
        $c = new Http();
        $this->assertTrue($c->login(TEST_ADMIN_LOGIN, TEST_ADMIN_PASSWORD), 'admin login failed');
        return $c;
    }

    protected function agent(): Http
    {
        $c = new Http();
        $this->assertTrue($c->login(TEST_AGENT_LOGIN, TEST_AGENT_PASSWORD), 'agent login failed');
        return $c;
    }

    protected function guest(): Http
    {
        $c = new Http();
        $this->assertTrue($c->login(TEST_GUEST_LOGIN, TEST_GUEST_PASSWORD), 'guest login failed');
        return $c;
    }

    protected function anon(): Http
    {
        return new Http();
    }

    protected function token(): string
    {
        return bin2hex(random_bytes(16));
    }

    // Валидное описание Quill Delta (URL-encoded JSON) для формы
    protected function delta(string $text = 'Тестовое описание'): string
    {
        return rawurlencode(json_encode(['ops' => [['insert' => $text . "\n"]]], JSON_UNESCAPED_UNICODE));
    }

    // Небольшой валидный JPEG во временном файле; возвращает путь
    protected function makeJpeg(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'jpg') . '.jpg';
        // Минимальный валидный JPEG 1x1 (без расширения GD в образе)
        $hex = 'ffd8ffe000104a46494600010100000100010000ffdb004300080606070605080707'
             . '070909080a0c140d0c0b0b0c1912130f141d1a1f1e1d1a1c1c20242e2720222c231c1c28'
             . '37292c30313434341f27393d38323c2e333432ffc0000b08000100010101110'
             . '0ffc4001f0000010501010101010100000000000000000102030405060708090a0b'
             . 'ffc400b5100002010303020403050504040000017d01020300041105122131410613516107'
             . '227114328191a1082342b1c11552d1f02433627282090a161718191a25262728292a3435363738393a'
             . '434445464748494a535455565758595a636465666768696a737475767778797a838485868788898a'
             . '92939495969798999aa2a3a4a5a6a7a8a9aab2b3b4b5b6b7b8b9bac2c3c4c5c6c7c8c9ca'
             . 'd2d3d4d5d6d7d8d9dae1e2e3e4e5e6e7e8e9eaf1f2f3f4f5f6f7f8f9fa'
             . 'ffda0008010100003f00fbd3ffd9';
        file_put_contents($path, hex2bin($hex));
        return $path;
    }

    protected function maxPropId(): int
    {
        return (int) Db::scalar('SELECT COALESCE(MAX(ID_NL_PROP_RESALE), 0) FROM NL_PROP_RESALE');
    }
}
