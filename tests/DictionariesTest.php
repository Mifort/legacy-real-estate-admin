<?php
namespace Tests;

class DictionariesTest extends IntegrationTestCase
{
    public function testSeedCounts(): void
    {
        $this->assertGreaterThanOrEqual(5, (int) Db::scalar('SELECT COUNT(*) FROM NL_MATERIAL'));
        $this->assertGreaterThanOrEqual(5, (int) Db::scalar('SELECT COUNT(*) FROM NL_HOUSES'));
    }

    public function testHouseTypeLinksToMaterial(): void
    {
        $material = Db::scalar("SELECT ID_NL_MATERIAL FROM NL_HOUSES WHERE NL_HOUSES_SHORT = 'Сталинка'");
        $this->assertSame('1', (string) $material);
    }

    public function testPropResaleGridHasHouseAndMaterialColumns(): void
    {
        $body = $this->admin()->get('/admin/php/jqgrid.table.php?tblName=NL_PROP_RESALE')['body'];
        $this->assertStringContainsString('Тип дома', $body);
        $this->assertStringContainsString('Материал дома', $body);
        $this->assertStringContainsString('1:Сталинка', $body);
        $this->assertStringContainsString('1:Кирпич', $body);
    }

    public function testDictTabsListMaterialAndHouses(): void
    {
        $body = $this->admin()->get('/admin/parts/dicts.php')['body'];
        $this->assertStringContainsString('tblName=NL_MATERIAL', $body);
        $this->assertStringContainsString('Материал дома', $body);
        $this->assertStringContainsString('tblName=NL_HOUSES', $body);
        $this->assertStringContainsString('Тип дома', $body);
    }

    public function testAgentCannotOpenDicts(): void
    {
        $this->assertSame(403, $this->agent()->get('/admin/parts/dicts.php')['status']);
    }

    public function testNewFlatStoresHouseAndMaterial(): void
    {
        $c = $this->admin();
        $before = $this->maxPropId();
        $token = $this->token();
        try {
            $r = $c->postCsrf('/admin/php/jqgrid.edit.php?tblName=NL_PROP_RESALE', [
                'oper' => 'add', 'id' => '_empty', 'form_submit' => $token,
                'NL_PROP_RESALE_AREA_FULL' => '40',
                'ID_NL_VIEW' => '1',
                'ID_NL_HOUSES' => '1',
                'ID_NL_MATERIAL' => '1',
                'NL_PROP_RESALE_PHOTO_URLS' => '[]',
                'NL_PROP_RESALE_DESCRIPTION' => $this->delta('Квартира в сталинке'),
            ]);
            $this->assertSame(200, $r['status']);
            $id = $this->maxPropId();
            $this->assertGreaterThan($before, $id);
            $this->assertSame('1', (string) Db::scalar('SELECT ID_NL_HOUSES FROM NL_PROP_RESALE WHERE ID_NL_PROP_RESALE = ' . $id));
            $this->assertSame('1', (string) Db::scalar('SELECT ID_NL_MATERIAL FROM NL_PROP_RESALE WHERE ID_NL_PROP_RESALE = ' . $id));
        } finally {
            Db::exec('DELETE FROM NL_PROP_RESALE WHERE ID_NL_PROP_RESALE > ' . $before);
            Db::exec('DELETE FROM NL_FORM_SUBMIT WHERE NL_FORM_SUBMIT_TOKEN = ' . Db::quote($token));
        }
    }

    public function testAddMaterialGetsDatabaseAssignedId(): void
    {
        $c = $this->admin();
        $before = (int) Db::scalar('SELECT COALESCE(MAX(ID_NL_MATERIAL),0) FROM NL_MATERIAL');
        $r = $c->postCsrf('/admin/php/jqgrid.edit.php?tblName=NL_MATERIAL', [
            'oper' => 'add', 'id' => '_empty',
            'form_submit' => $this->token(),
            'NL_MATERIAL_SHORT' => 'ТестМат',
        ]);
        try {
            $this->assertSame(200, $r['status']);
            $after = (int) Db::scalar('SELECT COALESCE(MAX(ID_NL_MATERIAL),0) FROM NL_MATERIAL');
            $this->assertSame($before + 1, $after);
        } finally {
            Db::exec('DELETE FROM NL_MATERIAL WHERE NL_MATERIAL_SHORT = ' . Db::quote('ТестМат'));
        }
    }
}
