<?php

declare(strict_types=1);

namespace wmd\craftproductpricehistory\services;

use Craft;
use craft\base\Component;
use craft\helpers\FileHelper;
use craft\helpers\Json;
use DateTime;
use DateTimeZone;
use InvalidArgumentException;
use wmd\craftproductpricehistory\helpers\PriceListSource;

/**
 * The price-list file of a shop whose prices are not in Commerce (setting
 * `priceListSource` = `file`): the shop keeps its price list in a
 * spreadsheet and uploads it; every publish run writes the last uploaded
 * version with that day's date until a new one replaces it.
 *
 * The parsed rows are kept under `@storage/omnibus/price-list-source`
 * (`rows.json`) next to a copy of the uploaded file.
 *
 * @since 1.4.0
 */
class PriceListSourceService extends Component
{
    /** Extensions read through PhpSpreadsheet; CSV is read without it. */
    public const SPREADSHEET_EXTENSIONS = ['xlsx', 'xls', 'ods'];

    public const CSV_EXTENSIONS = ['csv', 'txt'];

    private ?array $_data = null;

    public function dir(): string
    {
        return Craft::getAlias('@storage/omnibus/price-list-source');
    }

    /**
     * Reads a spreadsheet or CSV and, unless `$dryRun` or the file cannot be
     * used, makes it the price-list source. Rows with an error are left out
     * and listed in `errors`; a file with no usable rows, or without the
     * required columns, is refused (`fatal`) and the previous one stays.
     *
     * @return array{rows: int, errors: list<string>, fatal: string|null, columns: array<string, string>, saved: bool}
     * @throws InvalidArgumentException when the file cannot be read at all
     */
    public function import(string $path, string $fileName, bool $dryRun = false): array
    {
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $parsed = PriceListSource::parse(
            $this->readTable($path, $extension),
            static fn(string $message, array $params) => Craft::t('omnibus', $message, $params),
        );
        $result = [
            'rows' => count($parsed['rows']),
            'errors' => $parsed['errors'],
            'fatal' => $parsed['fatal'],
            'columns' => $parsed['columns'],
            'saved' => false,
        ];
        if ($parsed['fatal'] !== null || $dryRun) {
            return $result;
        }

        $dir = $this->dir();
        FileHelper::createDirectory($dir);
        foreach (glob("$dir/original.*") ?: [] as $old) {
            @unlink($old);
        }
        copy($path, "$dir/original." . ($extension !== '' ? $extension : 'dat'));

        $user = Craft::$app->has('user', true) ? Craft::$app->getUser()->getIdentity() : null;
        $data = [
            'fileName' => $fileName,
            'importedAt' => (new DateTime('now', new DateTimeZone('UTC')))->format(DATE_ATOM),
            'userId' => $user?->id,
            'userName' => $user ? (string)$user->getFriendlyName() : null,
            'columns' => $parsed['columns'],
            'errors' => $parsed['errors'],
            'rows' => $parsed['rows'],
        ];
        FileHelper::writeToFile("$dir/rows.json", Json::encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $this->_data = $data;
        $result['saved'] = true;

        return $result;
    }

    /**
     * The uploaded file's details without its rows, or null when none is.
     *
     * @return array{fileName: string, importedAt: DateTime, userName: string|null, columns: array<string, string>, errors: list<string>, rows: int}|null
     */
    public function info(): ?array
    {
        $data = $this->data();
        if ($data === null) {
            return null;
        }

        return [
            'fileName' => (string)$data['fileName'],
            'importedAt' => new DateTime((string)$data['importedAt']),
            'userName' => $data['userName'] ?? null,
            'columns' => (array)($data['columns'] ?? []),
            'errors' => (array)($data['errors'] ?? []),
            'rows' => count((array)$data['rows']),
        ];
    }

    /**
     * The rows of the uploaded file.
     *
     * @return list<array<string, string>>
     * @throws InvalidArgumentException when no file has been uploaded
     */
    public function rows(): array
    {
        $data = $this->data();
        if ($data === null) {
            throw new InvalidArgumentException('The price list comes from a file (setting "priceListSource"), but no file has been uploaded yet: Utilities → ' . Craft::t('omnibus', 'Price list file') . ', or `craft omnibus/price-list/import <file>`.');
        }

        return array_values((array)$data['rows']);
    }

    /**
     * The cells of the first sheet (or the CSV), row by row.
     *
     * @return list<list<mixed>>
     */
    public function readTable(string $path, string $extension): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new InvalidArgumentException("Cannot read $path.");
        }

        if (in_array($extension, self::CSV_EXTENSIONS, true)) {
            return PriceListSource::readCsv((string)file_get_contents($path));
        }

        if (in_array($extension, self::SPREADSHEET_EXTENSIONS, true)) {
            if (!class_exists(\PhpOffice\PhpSpreadsheet\IOFactory::class)) {
                throw new InvalidArgumentException('Excel and OpenDocument files need the phpoffice/phpspreadsheet package. Save the sheet as CSV, or require the package.');
            }
            try {
                $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($path);
                $reader->setReadDataOnly(true);
                $sheet = $reader->load($path)->getSheet(0);
                // Calculated values, unformatted: numbers stay numbers.
                return array_map('array_values', $sheet->toArray(null, true, false, false));
            } catch (\Throwable $e) {
                throw new InvalidArgumentException('The file could not be read as a spreadsheet: ' . $e->getMessage(), 0, $e);
            }
        }

        throw new InvalidArgumentException("Unsupported file type \"$extension\": use " . implode(', ', [...self::SPREADSHEET_EXTENSIONS, ...self::CSV_EXTENSIONS]) . '.');
    }

    private function data(): ?array
    {
        if ($this->_data !== null) {
            return $this->_data;
        }
        $path = $this->dir() . '/rows.json';
        if (!is_file($path)) {
            return null;
        }
        $data = Json::decodeIfJson((string)file_get_contents($path));

        return $this->_data = is_array($data) && isset($data['rows']) ? $data : null;
    }
}
