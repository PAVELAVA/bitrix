<?php
use Bitrix\Main\Web\HttpClient;

//
// Обработчики событий CRM, которые после сохранения сделки
// рассчитывают расстояние между адресами погрузки и выгрузки
// и записывают его в пользовательское поле.
//
AddEventHandler('crm', 'OnAfterCrmDealAdd', ['DistanceHandler', 'onAfterDealSave']);
AddEventHandler('crm', 'OnAfterCrmDealUpdate', ['DistanceHandler', 'onAfterDealSave']);

/**
 * Класс-обработчик сохранения сделки
 */
class DistanceHandler
{
    /** код поля "Адрес погрузки" */
    public const FIELD_FROM = 'UF_CRM_5F1BFC2852C3C';
    /** код поля "Адрес выгрузки" */
    public const FIELD_TO = 'UF_CRM_5F1BFC285BA35';
    /** код поля "Расстояние" */
    public const FIELD_DISTANCE = 'UF_CRM_1756877045179';

    /**
     * Запускается после сохранения сделки
     */
    public static function onAfterDealSave(array $fields): void
    {
        $from = $fields[self::FIELD_FROM] ?? '';
        $to = $fields[self::FIELD_TO] ?? '';

        // если один из адресов не указан, выходим
        if ($from === '' || $to === '') {
            return;
        }

        $distance = DistanceService::calculate($from, $to);
        if ($distance !== null) {
            $deal = new \CCrmDeal(false);
            $deal->Update($fields['ID'], [self::FIELD_DISTANCE => $distance]);
        }
    }
}

/**
 * Сервис для расчёта расстояния через OpenStreetMap
 */
class DistanceService
{
    /**
     * Возвращает расстояние в километрах между двумя адресами
     */
    public static function calculate(string $from, string $to): ?float
    {
        $fromCoords = self::geocode($from);
        $toCoords = self::geocode($to);

        if ($fromCoords === null || $toCoords === null) {
            return null;
        }

        $http = new HttpClient(['timeout' => 5]);
        $url = sprintf(
            'https://router.project-osrm.org/route/v1/driving/%s,%s;%s,%s?overview=false',
            $fromCoords['lon'],
            $fromCoords['lat'],
            $toCoords['lon'],
            $toCoords['lat']
        );

        $response = $http->get($url);
        if ($http->getStatus() !== 200) {
            return null;
        }

        $data = json_decode($response, true);
        $distance = $data['routes'][0]['distance'] ?? null;
        if ($distance === null) {
            return null;
        }

        // перевод из метров в километры
        return round($distance / 1000, 2);
    }

    /**
     * Получает координаты адреса через сервис Nominatim
     */
    private static function geocode(string $address): ?array
    {
        $http = new HttpClient([
            'timeout' => 5,
            'headers' => ['User-Agent' => 'BitrixDistance/1.0']
        ]);

        $query = http_build_query([
            'format' => 'json',
            'limit' => 1,
            'countrycodes' => 'ru',
            'q' => $address,
        ]);

        $response = $http->get('https://nominatim.openstreetmap.org/search?' . $query);
        if ($http->getStatus() !== 200) {
            return null;
        }

        $data = json_decode($response, true);
        if (empty($data[0]['lat']) || empty($data[0]['lon'])) {
            return null;
        }

        return [
            'lat' => $data[0]['lat'],
            'lon' => $data[0]['lon'],
        ];
    }
}
