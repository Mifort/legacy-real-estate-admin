<?php
namespace Tests;

class AuthTest extends IntegrationTestCase
{
    public function testAdminLoginSucceeds(): void
    {
        $c = $this->admin();
        $this->assertSame(200, $c->get('/admin/')['status']);
    }

    public function testWrongPasswordRejected(): void
    {
        $c = new Http();
        $this->assertFalse($c->login(TEST_ADMIN_LOGIN, 'definitely-wrong-password'));
    }

    public function testSqlInjectionLoginRejected(): void
    {
        $c = new Http();
        $this->assertFalse($c->login("admin' OR 1=1 -- -", 'x'));
    }

    public function testEndpointRequiresAuth(): void
    {
        $r = $this->anon()->get('/admin/php/jqgrid.show.php?tblName=NL_PROP_RESALE&sidx=ID_NL_PROP_RESALE&sord=ASC&page=1&rows=50');
        $this->assertSame(403, $r['status']);
    }

    public function testAgentCannotReachAdminOnlyTable(): void
    {
        $r = $this->agent()->get('/admin/php/jqgrid.show.php?tblName=NL_USER&sidx=ID_NL_USER&sord=ASC&page=1&rows=50');
        $this->assertSame(403, $r['status']);
    }

    public function testAgentCanReadJournal(): void
    {
        $r = $this->agent()->get('/admin/php/jqgrid.show.php?tblName=NL_PROP_RESALE&sidx=ID_NL_PROP_RESALE&sord=ASC&page=1&rows=50');
        $this->assertSame(200, $r['status']);
    }

    public function testUnknownTableRejected(): void
    {
        $r = $this->admin()->get('/admin/php/jqgrid.show.php?tblName=NL_SECRET&sidx=x&sord=ASC&page=1&rows=50');
        $this->assertSame(404, $r['status']);
    }
}
