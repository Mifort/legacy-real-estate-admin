<?php
namespace Tests;

class XssTest extends IntegrationTestCase
{
    // Экранирование ячеек грида происходит на клиенте (textFormatter в jqGrid), поэтому по HTTP
    // проверяется только то, что сервер не даёт разметке разорвать XML-структуру ответа:
    // значение уходит внутри CDATA, экранируется выход "]]>". Исполнение в браузере проверено
    // headless-тестом отдельно (см. REPORT.md).
    public function testGridKeepsStoredMarkupInsideCdata(): void
    {
        $original = Db::scalar('SELECT NL_PROP_RESALE_ADDRESS FROM NL_PROP_RESALE WHERE ID_NL_PROP_RESALE = 3');
        Db::exec("UPDATE NL_PROP_RESALE SET NL_PROP_RESALE_ADDRESS = " . Db::quote(']]><script>alert(1)</script>') . " WHERE ID_NL_PROP_RESALE = 3");
        try {
            $body = $this->admin()->get('/admin/php/jqgrid.show.php?tblName=NL_PROP_RESALE&sidx=ID_NL_PROP_RESALE&sord=ASC&page=1&rows=50')['body'];
            // XML корректно парсится и не содержит «сырого» закрытия CDATA с последующим тегом
            $this->assertStringNotContainsString(']]><script>', $body);
            $xml = @simplexml_load_string($body);
            $this->assertNotFalse($xml, 'ответ грида должен оставаться валидным XML');
        } finally {
            $this->restoreColumn('NL_PROP_RESALE_ADDRESS', $original);
        }
    }

    public function testLandingEscapesAddress(): void
    {
        $original = Db::scalar('SELECT NL_PROP_RESALE_ADDRESS FROM NL_PROP_RESALE WHERE ID_NL_PROP_RESALE = 3');
        Db::exec("UPDATE NL_PROP_RESALE SET NL_PROP_RESALE_ADDRESS = " . Db::quote('ул. "Мира" & <Ленина>') . " WHERE ID_NL_PROP_RESALE = 3");
        try {
            $body = $this->anon()->get('/')['body'];
            $this->assertStringNotContainsString('<Ленина>', $body);
            $this->assertStringContainsString('&lt;Ленина&gt;', $body);
            $this->assertStringContainsString('ул. &quot;Мира&quot;', $body);
        } finally {
            $this->restoreColumn('NL_PROP_RESALE_ADDRESS', $original);
        }
    }

    public function testLandingRendersEscapedQuillText(): void
    {
        $original = Db::scalar('SELECT NL_PROP_RESALE_DESCRIPTION FROM NL_PROP_RESALE WHERE ID_NL_PROP_RESALE = 3');
        $delta = rawurlencode(json_encode(['ops' => [['insert' => "Опасно <script>alert(1)</script>\n"]]], JSON_UNESCAPED_UNICODE));
        Db::exec("UPDATE NL_PROP_RESALE SET NL_PROP_RESALE_DESCRIPTION = " . Db::quote($delta) . " WHERE ID_NL_PROP_RESALE = 3");
        try {
            $body = $this->anon()->get('/')['body'];
            $this->assertStringContainsString('Опасно', $body);
            $this->assertStringContainsString('&lt;script&gt;', $body);
            $this->assertStringNotContainsString('<script>alert(1)</script>', $body);
        } finally {
            $this->restoreColumn('NL_PROP_RESALE_DESCRIPTION', $original);
        }
    }

    public function testLandingNeutralizesJavascriptLink(): void
    {
        $original = Db::scalar('SELECT NL_PROP_RESALE_DESCRIPTION FROM NL_PROP_RESALE WHERE ID_NL_PROP_RESALE = 3');
        $delta = rawurlencode(json_encode([
            'ops' => [
                ['insert' => 'click', 'attributes' => ['link' => 'javascript:alert(1)']],
                ['insert' => "\n"],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        Db::exec("UPDATE NL_PROP_RESALE SET NL_PROP_RESALE_DESCRIPTION = " . Db::quote($delta) . " WHERE ID_NL_PROP_RESALE = 3");
        try {
            $body = $this->anon()->get('/')['body'];
            $this->assertStringContainsString('>click</a>', $body);
            $this->assertStringContainsString('href="#"', $body);
            $this->assertStringNotContainsString('javascript:', $body);
        } finally {
            $this->restoreColumn('NL_PROP_RESALE_DESCRIPTION', $original);
        }
    }

    public function testLandingHidesOwnerPhone(): void
    {
        $marker = 'OWNER-SECRET-79001112233';
        $original = Db::scalar('SELECT NL_PROP_RESALE_PHONE_OWNER FROM NL_PROP_RESALE WHERE ID_NL_PROP_RESALE = 3');
        Db::exec("UPDATE NL_PROP_RESALE SET NL_PROP_RESALE_PHONE_OWNER = " . Db::quote($marker) . " WHERE ID_NL_PROP_RESALE = 3");
        try {
            $body = $this->anon()->get('/')['body'];
            $this->assertStringNotContainsString($marker, $body);
        } finally {
            $this->restoreColumn('NL_PROP_RESALE_PHONE_OWNER', $original);
        }
    }

    private function restoreColumn(string $column, $original): void
    {
        $value = $original === null ? 'NULL' : Db::quote((string) $original);
        Db::exec('UPDATE NL_PROP_RESALE SET ' . $column . ' = ' . $value . ' WHERE ID_NL_PROP_RESALE = 3');
    }
}
