<?php
namespace Tests;

class PhotoDraftTest extends IntegrationTestCase
{
    private function draftKey(): string
    {
        return bin2hex(random_bytes(16));
    }

    public function testUploadUnderOwnDraftSucceeds(): void
    {
        $c = $this->agent();
        $jpeg = $this->makeJpeg();
        $r = $c->upload('/admin/php/file.upload.php?tblName=NL_PROP_RESALE', $jpeg, [
            'table' => 'NL_PROP_RESALE', 'col' => 'NL_PROP_RESALE_PHOTO_URLS',
            'id' => 'd' . $this->draftKey(),
        ]);
        @unlink($jpeg);
        $this->assertSame(200, $r['status']);
        $this->assertStringContainsString('/img/prop_resale/', $r['body']);
    }

    public function testForeignDraftKeyRejected(): void
    {
        $key = $this->draftKey();
        // владелец захватывает ключ
        $owner = $this->admin();
        $jpeg = $this->makeJpeg();
        $owner->upload('/admin/php/file.upload.php?tblName=NL_PROP_RESALE', $jpeg, [
            'table' => 'NL_PROP_RESALE', 'col' => 'NL_PROP_RESALE_PHOTO_URLS', 'id' => 'd' . $key,
        ]);
        // другой пользователь с тем же ключом
        $attacker = $this->agent();
        $r = $attacker->upload('/admin/php/file.upload.php?tblName=NL_PROP_RESALE', $jpeg, [
            'table' => 'NL_PROP_RESALE', 'col' => 'NL_PROP_RESALE_PHOTO_URLS', 'id' => 'd' . $key,
        ]);
        @unlink($jpeg);
        $this->assertSame(403, $r['status']);
    }

    public function testGuestCannotUpload(): void
    {
        $c = $this->guest();
        $jpeg = $this->makeJpeg();
        $r = $c->upload('/admin/php/file.upload.php?tblName=NL_PROP_RESALE', $jpeg, [
            'table' => 'NL_PROP_RESALE', 'col' => 'NL_PROP_RESALE_PHOTO_URLS', 'id' => 'd' . $this->draftKey(),
        ]);
        @unlink($jpeg);
        $this->assertSame(403, $r['status']);
    }

    public function testNonImageRejected(): void
    {
        $c = $this->agent();
        $bad = tempnam(sys_get_temp_dir(), 'php') . '.jpg';
        file_put_contents($bad, "<?php echo 'x';");
        $r = $c->upload('/admin/php/file.upload.php?tblName=NL_PROP_RESALE', $bad, [
            'table' => 'NL_PROP_RESALE', 'col' => 'NL_PROP_RESALE_PHOTO_URLS', 'id' => 'd' . $this->draftKey(),
        ]);
        @unlink($bad);
        $this->assertSame(415, $r['status']);
    }

    public function testCannotReferenceForeignFile(): void
    {
        // существующий файл квартиры 3
        $existing = Db::scalar("SELECT JSON_UNQUOTE(JSON_EXTRACT(NL_PROP_RESALE_PHOTO_URLS, '$[0]')) FROM NL_PROP_RESALE WHERE ID_NL_PROP_RESALE = 3 AND NL_PROP_RESALE_PHOTO_URLS IS NOT NULL");
        if (!$existing) {
            $this->markTestSkipped('у квартиры 3 нет фото в этом дампе');
        }
        $c = $this->agent();
        $before = $this->maxPropId();
        $r = $c->postCsrf('/admin/php/jqgrid.edit.php?tblName=NL_PROP_RESALE', [
            'oper' => 'add', 'id' => '_empty', 'form_submit' => $this->token(),
            'NL_PROP_RESALE_AREA_FULL' => '50', 'ID_NL_VIEW' => '1',
            'NL_PROP_RESALE_PHOTO_URLS' => json_encode([$existing]),
            'NL_PROP_RESALE_DESCRIPTION' => $this->delta(),
        ]);
        $this->assertSame(400, $r['status']);
        $this->assertSame($before, $this->maxPropId());
    }
}
