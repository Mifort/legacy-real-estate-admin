<?php
namespace Tests;

class SecurityTest extends IntegrationTestCase
{
    public function testServiceFilesBlocked(): void
    {
        foreach (['/README.md', '/REPORT.md', '/.env', '/.local-secrets/admin-password',
                  '/scripts/setup-secrets.php', '/sql/testdb.sql', '/composer.json',
                  '/docker-compose.yml'] as $path) {
            $this->assertSame(403, $this->anon()->get($path)['status'], "$path must be 403");
        }
    }

    public function testImgDirectoryDoesNotExecutePhp(): void
    {
        Db::exec("SELECT 1"); // ensure connection
        $probe = $_SERVER['DOCUMENT_ROOT'] ?? '/var/www/html';
        @file_put_contents($probe . '/img/prop_resale/_sec_probe.php', "<?php echo 'EXECUTED';");
        $r = $this->anon()->get('/img/prop_resale/_sec_probe.php');
        @unlink($probe . '/img/prop_resale/_sec_probe.php');
        $this->assertSame(403, $r['status']);
        $this->assertStringNotContainsString('EXECUTED', $r['body']);
    }

    public function testPasswordNotLeakedInUserGrid(): void
    {
        $r = $this->admin()->get('/admin/php/jqgrid.show.php?tblName=NL_USER&sidx=ID_NL_USER&sord=ASC&page=1&rows=50');
        $this->assertSame(200, $r['status']);
        $this->assertStringNotContainsString('$2y$', $r['body']);
        $this->assertStringNotContainsString(TEST_AGENT_PASSWORD, $r['body']);
    }

    public function testPostWithoutCsrfRejected(): void
    {
        $c = $this->admin();
        $r = $c->post('/admin/php/jqgrid.edit.php?tblName=NL_PROP_RESALE', ['oper' => 'del', 'id' => 999999]);
        $this->assertSame(403, $r['status']);
    }

    public function testGetOnMutatingEndpointsRejected(): void
    {
        $c = $this->admin();
        foreach (['/admin/php/logout.php', '/admin/php/onlymy.php',
                  '/admin/php/jqgrid.edit.php?tblName=NL_PROP_RESALE',
                  '/admin/php/file.upload.php'] as $path) {
            $this->assertSame(405, $c->get($path)['status'], "$path GET must be 405");
        }
    }

    public function testLandingLoads(): void
    {
        $r = $this->anon()->get('/');
        $this->assertSame(200, $r['status']);
        $this->assertStringContainsString('Типы домов', $r['body']);
        $this->assertStringContainsString('Квартиры', $r['body']);
        $this->assertStringContainsString('Сталинка', $r['body']);
        $this->assertStringContainsString('Кирпич', $r['body']);
        $this->assertStringContainsString('Светлая квартира', $r['body']);
    }
}
