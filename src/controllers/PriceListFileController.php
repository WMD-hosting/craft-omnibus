<?php

declare(strict_types=1);

namespace wmd\craftproductpricehistory\controllers;

use Craft;
use craft\web\Controller;
use craft\web\UploadedFile;
use InvalidArgumentException;
use wmd\craftproductpricehistory\ProductHistory;
use wmd\craftproductpricehistory\utilities\PriceListFileUtility;
use yii\web\Response;

/**
 * Takes the uploaded price-list spreadsheet (Utilities → Price list file).
 *
 * @since 1.4.0
 */
class PriceListFileController extends Controller
{
    public function actionUpload(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('utility:' . PriceListFileUtility::id());

        $plugin = ProductHistory::$plugin;
        $session = Craft::$app->getSession();
        $session->remove(PriceListFileUtility::FLASH_KEY);
        $redirect = $this->redirect('utilities/' . PriceListFileUtility::id());

        $file = UploadedFile::getInstanceByName('priceListFile');
        if ($file === null || $file->getHasError()) {
            $this->setFailFlash(Craft::t('omnibus', 'Choose a file to upload.'));
            return $redirect;
        }

        try {
            $result = $plugin->getPriceListSource()->import($file->tempName, $file->name);
        } catch (InvalidArgumentException $e) {
            $this->setFailFlash($e->getMessage());
            return $redirect;
        } catch (\Throwable $e) {
            Craft::error('Price list upload failed: ' . $e->getMessage(), __METHOD__);
            $this->setFailFlash(Craft::t('omnibus', 'The file could not be read.'));
            return $redirect;
        }

        $result['fileName'] = $file->name;
        $result['published'] = null;
        if ($result['saved'] && $this->request->getBodyParam('publishNow') && $plugin->getSettings()->priceListEnabled) {
            try {
                $published = $plugin->getPriceList()->publish();
                $result['published'] = array_sum(array_column($published['files'], 'rows'));
            } catch (\Throwable $e) {
                Craft::error('Price list publish after upload failed: ' . $e->getMessage(), __METHOD__);
                $result['published'] = false;
            }
        }
        // The full list of left-out rows is in the saved file; the session keeps a page of it.
        $result['errorCount'] = count($result['errors']);
        $result['errors'] = array_slice($result['errors'], 0, 100);
        $session->set(PriceListFileUtility::FLASH_KEY, $result);

        if ($result['saved']) {
            $this->setSuccessFlash(Craft::t('omnibus', 'Price list file uploaded.'));
        } else {
            $this->setFailFlash(Craft::t('omnibus', 'The file was not used.'));
        }

        return $redirect;
    }
}
