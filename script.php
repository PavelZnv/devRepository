<?require_once($_SERVER['DOCUMENT_ROOT'] . "/bitrix/modules/main/include/prolog_before.php");?>
<?
use Bitrix\Main\Loader;
use Bitrix\Main\Application;
use Bitrix\Main\Data\Cache;
use Bitrix\Main\Type\DateTime;
use Bitrix\Iblock\ElementTable;
use Bitrix\Iblock\SectionTable;
use Bitrix\Iblock\PropertyTable;
use Bitrix\Iblock\ElementPropertyTable;
use Bitrix\Iblock\IblockTable;
Loader::includeModule('iblock');

$request = Application::getInstance()->getContext()->getRequest();
$iblockId = (int)$request->getQuery('iblockId');
$year = (int)$request->getQuery('year');
if (empty($iblockId) || empty($year)) {
    echo json_encode([
        'error' => 'Не указаны обязательные параметры: iblockId или year'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
$pageSize = (int)$request->getQuery('pageSize') ?: 10;
$page = (int)$request->getQuery('page') ?: 1;
$cacheTime = 86400;
$cacheKey = 'iblockNews' . $year . '_' . $pageSize . '_' . $page;
$cacheDirPath = '/iblockNews/' . $year;
$cache = Cache::createInstance();

if ($cache->initCache($cacheTime, $cacheKey, $cacheDirPath)) {
    $result = $cache->getVars();
    $cache->output();
} elseif ($cache->startDataCache()) {
    $filter = [
        'IBLOCK_ID' => $iblockId,
        'ACTIVE' => 'Y',
        '>=ACTIVE_FROM' => new DateTime("{$year}-01-01 00:00:00", 'Y-m-d H:i:s'),
        '<=ACTIVE_FROM' => new DateTime("{$year}-12-31 23:59:59", 'Y-m-d H:i:s')
    ];
    $select = [
        'ID',
        'NAME',
        'PREVIEW_PICTURE',
        'ACTIVE_FROM',
        'IBLOCK_ID',
        'IBLOCK_SECTION_ID',
        'CODE',
        'DETAIL_PAGE_URL' => 'IBLOCK.DETAIL_PAGE_URL',
        'TAGS'
    ];
    $result = [];
    $res = ElementTable::getList([
        'filter' => $filter,
        'select' => $select,
        'order' => ['ID' => 'ASC'],
        'limit' => $pageSize,
        'offset' => ($page - 1) * $pageSize,
    ]);
    while ($item = $res->fetch()) {
        if ($item['IBLOCK_SECTION_ID']) {
            $section = SectionTable::getById($item['IBLOCK_SECTION_ID'])->fetch();
            if ($section) {
                $sectionName = $section['NAME'];
            }
        } else {
            $sectionName = null;
        }
        $authorPropResult = ElementPropertyTable::getList([
            'select' => ['VALUE'],
            'filter' => [
                'IBLOCK_ELEMENT_ID' => $item['ID'],
                'IBLOCK_PROPERTY_ID' => PropertyTable::getList([
                    'select' => ['ID'],
                    'filter' => [
                        'IBLOCK_ID' => $iblockId,
                        'CODE' => 'AUTHOR'
                    ]
                ])->fetch()['ID']
            ],
        ]);
        if ($authorProp = $authorPropResult->fetch()) {
            $authorElement = ElementTable::getById($authorProp['VALUE'])->fetch();
            if ($authorElement) {
                $authorName = explode(' ', $authorElement['NAME'])[0];
            }
        }
        
        $tags = !empty($item['TAGS']) ? array_map('trim', explode(',', $item['TAGS'])) : null;
        $item['DETAIL_PAGE_URL'] = CIBlock::ReplaceDetailUrl($item['DETAIL_PAGE_URL'], $item, false, 'E');
        
        $result[] = [
            'id' => $item['ID'],
            'url' => $item['DETAIL_PAGE_URL'],
            'image' => CFile::GetPath($item['PREVIEW_PICTURE']),
            'name' => $item['NAME'],
            'sectionName' => $sectionName,
            'date' => FormatDate("d F Y H:i", $item['ACTIVE_FROM']->getTimestamp()),
            'author' => $authorName,
            'tags' => $tags,
        ];
    }
    
    if (empty($result)) {
        $cache->abortDataCache();
    } else {
        $cache->endDataCache($result);
    }
}
header('Content-Type: application/json');
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
?>