<?php
/**
 * Тесты plogImageIdToStorageId / toGUIDv4 (как в Mongo GridFS).
 * Запуск: php server/tests/plog_image_id_test.php
 */
declare(strict_types=1);

namespace backends\files;

require_once __DIR__ . '/../backends/backend.php';
require_once __DIR__ . '/../backends/files/files.php';

final class plogstub_test extends files {
    public function __construct() {
        parent::__construct(['backends' => ['files' => []]], null, null, false);
    }
    public function addFile($realFileName, $stream, $metadata = []) {
        throw new \RuntimeException('stub');
    }
    public function getFile($uuid) {
        throw new \RuntimeException('stub');
    }
    public function getFileStream($uuid) {
        throw new \RuntimeException('stub');
    }
    public function getFileInfo($uuid) {
        throw new \RuntimeException('stub');
    }
    public function setFileMetadata($uuid, $metadata) {
        throw new \RuntimeException('stub');
    }
    public function getFileMetadata($uuid) {
        throw new \RuntimeException('stub');
    }
    public function searchFiles($query, $skip = 0, $limit = 1024) {
        throw new \RuntimeException('stub');
    }
    public function deleteFile($uuid) {
        throw new \RuntimeException('stub');
    }
    public function deleteFiles($query) {
        throw new \RuntimeException('stub');
    }
}

function plog_test_fail(string $msg): void {
    fwrite(STDERR, "FAIL: $msg\n");
    exit(1);
}

$GLOBALS['params'] = [];

$f = new plogstub_test();

$oid = 'aabbccddeeff001122334455';
$guid = $f->toGUIDv4($oid);
$r = $f->plogImageIdToStorageId($guid);
if ($r !== strtolower($oid)) {
    plog_test_fail("roundtrip dashed GUID: ожидали " . strtolower($oid) . ", получили $r");
}

$hex32 = str_replace('-', '', $guid);
$r2 = $f->plogImageIdToStorageId($hex32);
if ($r2 !== strtolower($oid)) {
    plog_test_fail("32 hex без дефисов: ожидали " . strtolower($oid) . ", получили $r2");
}

$r3 = $f->plogImageIdToStorageId(strtoupper($hex32));
if ($r3 !== strtolower($oid)) {
    plog_test_fail("32 hex upper: ожидали " . strtolower($oid) . ", получили $r3");
}

$r4 = $f->plogImageIdToStorageId($oid);
if ($r4 !== strtolower($oid)) {
    plog_test_fail("24 hex ObjectId: ожидали " . strtolower($oid) . ", получили $r4");
}

if ($f->plogImageIdToStorageId('') !== '') {
    plog_test_fail("пустая строка должна давать ''");
}

echo "OK plog_image_id_test\n";
exit(0);
