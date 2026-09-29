<?php
namespace Tests;

class PropertySaveTest extends IntegrationTestCase
{
    private function addFields(string $token, string $photos = '[]'): array
    {
        return [
            'oper' => 'add', 'id' => '_empty', 'form_submit' => $token,
            'NL_PROP_RESALE_AREA_FULL' => '50', 'ID_NL_VIEW' => '1',
            'NL_PROP_RESALE_PHOTO_URLS' => $photos,
            'NL_PROP_RESALE_DESCRIPTION' => $this->delta(),
        ];
    }

    public function testAddCreatesRecordWithDbId(): void
    {
        $c = $this->admin();
        $before = $this->propCount();
        $prevMax = $this->maxPropId();
        $r = $c->postCsrf('/admin/php/jqgrid.edit.php?tblName=NL_PROP_RESALE', $this->addFields($this->token()));
        $this->assertSame(200, $r['status']);
        $this->assertSame($before + 1, $this->propCount());
        // ID назначает база (AUTO_INCREMENT), он больше прежнего максимума
        $newId = $this->maxPropId();
        $this->assertGreaterThan($prevMax, $newId);
        Db::exec('DELETE FROM NL_PROP_RESALE WHERE ID_NL_PROP_RESALE = ' . $newId);
    }

    public function testClientSuppliedIdIsIgnored(): void
    {
        $c = $this->admin();
        $before = $this->propCount();
        $area3 = (string) Db::scalar('SELECT NL_PROP_RESALE_AREA_FULL FROM NL_PROP_RESALE WHERE ID_NL_PROP_RESALE = 3');
        $fields = $this->addFields($this->token());
        $fields['ID_NL_PROP_RESALE'] = '3'; // существующая квартира — должна игнорироваться
        $r = $c->postCsrf('/admin/php/jqgrid.edit.php?tblName=NL_PROP_RESALE', $fields);
        $this->assertSame(200, $r['status']);
        $this->assertSame($before + 1, $this->propCount(), 'должна создаться новая запись');
        $this->assertSame($area3, (string) Db::scalar('SELECT NL_PROP_RESALE_AREA_FULL FROM NL_PROP_RESALE WHERE ID_NL_PROP_RESALE = 3'), 'квартира 3 не должна измениться');
        Db::exec('DELETE FROM NL_PROP_RESALE WHERE ID_NL_PROP_RESALE = ' . $this->maxPropId());
    }

    public function testResubmitSameTokenIsIdempotent(): void
    {
        $c = $this->admin();
        $token = $this->token();
        $before = $this->maxPropId();
        $c->postCsrf('/admin/php/jqgrid.edit.php?tblName=NL_PROP_RESALE', $this->addFields($token));
        $c->postCsrf('/admin/php/jqgrid.edit.php?tblName=NL_PROP_RESALE', $this->addFields($token));
        $c->postCsrf('/admin/php/jqgrid.edit.php?tblName=NL_PROP_RESALE', $this->addFields($token));
        try {
            $created = (int) Db::scalar('SELECT COUNT(*) FROM NL_PROP_RESALE WHERE ID_NL_PROP_RESALE > ' . $before);
            $this->assertSame(1, $created, 'повтор с тем же ключом не должен создавать дубликаты');
        } finally {
            Db::exec('DELETE FROM NL_PROP_RESALE WHERE ID_NL_PROP_RESALE > ' . $before);
            Db::exec('DELETE FROM NL_FORM_SUBMIT WHERE NL_FORM_SUBMIT_TOKEN = ' . Db::quote($token));
        }
    }

    public function testConcurrentResubmitCreatesOneRecord(): void
    {
        $c = $this->admin();
        $token = $this->token();
        $before = $this->maxPropId();
        $base = rtrim(getenv('TEST_BASE_URL') ?: 'http://localhost', '/');
        // Один и тот же ключ отправки в 6 параллельных запросов одной сессии админа
        $mh = curl_multi_init();
        $handles = [];
        for ($i = 0; $i < 6; $i++) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $base . '/admin/php/jqgrid.edit.php?tblName=NL_PROP_RESALE',
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query($this->addFields($token)),
                CURLOPT_HTTPHEADER => ['X-CSRF-Token: ' . $c->csrf],
                CURLOPT_COOKIEFILE => $c->cookieJarPath(),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[] = $ch;
        }
        do { $st = curl_multi_exec($mh, $running); curl_multi_select($mh); } while ($running && $st == CURLM_OK);
        $codes = [];
        foreach ($handles as $ch) { $codes[] = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_multi_remove_handle($mh, $ch); curl_close($ch); }
        curl_multi_close($mh);
        try {
            $created = (int) Db::scalar('SELECT COUNT(*) FROM NL_PROP_RESALE WHERE ID_NL_PROP_RESALE > ' . $before);
            $this->assertSame(1, $created, '6 параллельных запросов с одним ключом → одна запись; коды: ' . implode(',', $codes));
        } finally {
            Db::exec('DELETE FROM NL_PROP_RESALE WHERE ID_NL_PROP_RESALE > ' . $before);
            Db::exec('DELETE FROM NL_FORM_SUBMIT WHERE NL_FORM_SUBMIT_TOKEN = ' . Db::quote($token));
        }
    }

    public function testMissingRequiredFieldRejected(): void
    {
        $c = $this->admin();
        $before = $this->maxPropId();
        $fields = $this->addFields($this->token());
        unset($fields['NL_PROP_RESALE_AREA_FULL']); // обязательное поле
        $r = $c->postCsrf('/admin/php/jqgrid.edit.php?tblName=NL_PROP_RESALE', $fields);
        $this->assertSame(400, $r['status']);
        $this->assertSame($before, $this->maxPropId());
    }

    public function testMissingPhotoFileRejected(): void
    {
        $c = $this->admin();
        $before = $this->maxPropId();
        $token = $this->token();
        $adminId = (int) Db::userId(TEST_ADMIN_LOGIN);
        // Ключ черновика совпадает с ключом формы и принадлежит admin, файла на диске нет:
        // отказ должен прийти от проверки существования, а не от чужого ключа.
        Db::exec(
            'INSERT INTO NL_UPLOAD_DRAFT (NL_UPLOAD_DRAFT_TOKEN, ID_NL_USER, NL_UPLOAD_DRAFT_TIME) VALUES ('
            . Db::quote($token) . ', ' . $adminId . ', NOW())'
        );
        try {
            $photos = json_encode(['/img/prop_resale/PHOTO_URLS_d' . $token . '_000000_000000_deadbeef.jpg']);
            $r = $c->postCsrf('/admin/php/jqgrid.edit.php?tblName=NL_PROP_RESALE', $this->addFields($token, $photos));
            $this->assertSame(400, $r['status']);
            $this->assertStringContainsString('не найден', $r['body']);
            $this->assertSame($before, $this->maxPropId());
        } finally {
            Db::exec('DELETE FROM NL_UPLOAD_DRAFT WHERE NL_UPLOAD_DRAFT_TOKEN = ' . Db::quote($token));
            Db::exec('DELETE FROM NL_FORM_SUBMIT WHERE NL_FORM_SUBMIT_TOKEN = ' . Db::quote($token));
            Db::exec('DELETE FROM NL_PROP_RESALE WHERE ID_NL_PROP_RESALE > ' . $before);
        }
    }

    public function testAgentCannotEditForeignRecord(): void
    {
        // квартира 3 принадлежит пользователю 1 (admin)
        Db::exec('UPDATE NL_PROP_RESALE SET ID_NL_USER = 1 WHERE ID_NL_PROP_RESALE = 3');
        $c = $this->agent();
        $r = $c->postCsrf('/admin/php/jqgrid.edit.php?tblName=NL_PROP_RESALE', [
            'oper' => 'edit', 'id' => '3', 'ID_NL_PROP_RESALE' => '3',
            'NL_PROP_RESALE_AREA_FULL' => '999', 'ID_NL_VIEW' => '1',
            'NL_PROP_RESALE_PHOTO_URLS' => '[]', 'NL_PROP_RESALE_DESCRIPTION' => $this->delta(),
        ]);
        $this->assertSame(403, $r['status']);
        $this->assertNotSame('999.00', (string) Db::scalar('SELECT NL_PROP_RESALE_AREA_FULL FROM NL_PROP_RESALE WHERE ID_NL_PROP_RESALE = 3'));
    }

    private function propCount(): int
    {
        return (int) Db::scalar('SELECT COUNT(*) FROM NL_PROP_RESALE');
    }
}
