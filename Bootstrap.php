<?php
/** @noinspection PhpUnused */
/** @noinspection PhpUnusedParameterInspection */

/**
 * The First Cash Solution Shopware Plugin is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * The First Cash Solution Shopware Plugin is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with First Cash Solution Shopware Plugin. If not, see <http://www.gnu.org/licenses/>.
 *
 * PHP version 5.6, 7 , 7.1
 *
 * @category  Payment
 * @package   First Cash Solution_Shopware5_Plugin
 * @author    FATCHIP GmbH <support@fatchip.de>
 * @copyright 2018 First Cash Solution
 * @license   <http://www.gnu.org/licenses/> GNU Lesser General Public License
 * @link      https://www.firstcashsolution.de/
 */

// needed for CSRF Protection compatibility SW versions < 5.2 lba
require_once __DIR__ . '/Components/CSRFWhitelistAware.php';


use Doctrine\Common\Collections\ArrayCollection;

use Doctrine\ORM\OptimisticLockException;
use Doctrine\ORM\ORMException;
use Fatchip\FCSPayment\CTResponse;
use Fatchip\FCSPayment\Encryption;
use Shopware\CustomModels\FatchipFCSApilog\FatchipFCSApilog;
use Shopware\Plugins\FatchipFCSPayment\Bootstrap\Forms;
use Shopware\Plugins\FatchipFCSPayment\Bootstrap\Attributes;
use Shopware\Plugins\FatchipFCSPayment\Bootstrap\Payments;
use Shopware\Plugins\FatchipFCSPayment\Bootstrap\Menu;
use Shopware\Plugins\FatchipFCSPayment\Bootstrap\RiskRules;
use Shopware\Plugins\FatchipFCSPayment\Bootstrap\Models;

use Shopware\Plugins\FatchipFCSPayment\Subscribers\ControllerPath;
use Shopware\Plugins\FatchipFCSPayment\Subscribers\Frontend\AfterPay;
use Shopware\Plugins\FatchipFCSPayment\Subscribers\Frontend\AmazonPay;
use Shopware\Plugins\FatchipFCSPayment\Subscribers\Frontend\AmazonPayCookie;
use Shopware\Plugins\FatchipFCSPayment\Subscribers\Frontend\Checkout;
use Shopware\Plugins\FatchipFCSPayment\Subscribers\Frontend\CreditCard;
use Shopware\Plugins\FatchipFCSPayment\Subscribers\Frontend\EasyCredit;
use Shopware\Plugins\FatchipFCSPayment\Subscribers\Frontend\KlarnaPayments;
use Shopware\Plugins\FatchipFCSPayment\Subscribers\Frontend\Logger;
use Shopware\Plugins\FatchipFCSPayment\Subscribers\Frontend\Debit;

use Shopware\Plugins\FatchipFCSPayment\Subscribers\Backend\Templates;
use Shopware\Plugins\FatchipFCSPayment\Subscribers\Backend\OrderList;
use Shopware\Plugins\FatchipFCSPayment\Subscribers\Frontend\PaypalExpress;
use Shopware\Plugins\FatchipFCSPayment\Subscribers\Frontend\PaypalExpressCookie;
use Shopware\Plugins\FatchipFCSPayment\Subscribers\Service;
use Shopware\Plugins\FatchipFCSPayment\Subscribers\TemplateRegistration;

/**
 * Class Shopware_Plugins_Frontend_FatchipFCSPayment_Bootstrap
 */
class Shopware_Plugins_Frontend_FatchipFCSPayment_Bootstrap extends Shopware_Components_Plugin_Bootstrap
{
    const pluginControllers = [
        'FatchipFCSAfterpay',
        'FatchipFCSAjax',
        'FatchipFCSAmazon',
        'FatchipFCSAmazonCheckout',
        'FatchipFCSAmazonRegister',
        'FatchipFCSCreditCard',
        'FatchipFCSEasyCredit',
        'FatchipFCSIdeal',
        'FatchipFCSKlarnaPayments',
        'FatchipFCSLastschrift',
        'FatchipFCSPaydirekt',
        'FatchipFCSPayment',
        'FatchipFCSPaypalExpress',
        'FatchipFCSPaypalExpressCheckout',
        'FatchipFCSPaypalExpressRegister',
        'FatchipFCSPaypalStandard',
        'FatchipFCSPostFinance',
        'FatchipFCSPrzelewy24',
        'FatchipFCSSofort'
    ];

    const blacklistConfigVar = 'sSEOVIEWPORTBLACKLIST';
    const blacklistDBConfigVar = 'seoviewportblacklist';
    const cronjobName = 'Cleanup Firstcash Payment Logs';

    // used only for testing OpenSSL cipher platform Support
    private String $encryption = 'blowfish';
    private String $blowfishPassword = 'blowfishPassword';

    /**
     * registers the custom models and plugin namespaces
     */
    public function afterInit()
    {
        $this->registerCustomModels();
        $this->registerComponents();
    }

    /**
     * plugin install method
     * @return array|bool
     * @throws Exception
     */
    public function install()
    {
        $minimumVersion = $this->getInfo()['compatibility']['minimumVersion'];
        if (!$this->assertMinimumVersion($minimumVersion)) {
            throw new RuntimeException("At least Shopware {$minimumVersion} is required");
        }

        $this->removeOldPayments();

        // Helper Classes
        $forms = new Forms();
        $attributes = new Attributes();
        $payments = new Payments();
        $menu = new Menu();
        $riskRules = new RiskRules();
        $models = new Models();

        $forms->createForm();
        $this->addFormTranslations(\Fatchip\FCSPayment\CTPaymentConfigForms::formTranslations);
        $attributes->createAttributes();
        $payments->createPayments();
        $menu->createMenu();
        $riskRules->createRiskRules();
        $models->createModels();

        $this->registerJavascript();

        $this->subscribeEvent('Enlight_Controller_Front_DispatchLoopStartup', 'onStartDispatch');

        $this->createCronJob(self::cronjobName, 'cleanupPaymentLogs', 86400, true);
        $this->subscribeEvent('Shopware_CronJob_CleanupPaymentLogs', 'cleanupPaymentLogs');

        try {
            $this->checkOpenSSLSupport();
        } catch (Exception $e) {
            Shopware()->Container()->get('shopware.snippet_database_handler')
                ->loadToDatabase($this->Path() . '/Snippets/');
            $message = Shopware()->Snippets()
                ->getNamespace('frontend/FatchipCTPayment/translations')
                ->get('errorBlowfishNotSupported');
            return ['success' => true, 'message' => $message];
        }

        return ['success' => true];
    }

    /**
     * Registers the snippet directory, needed for backend snippets
     *
     */
    public function registerSnippets()
    {
        $this->Application()->Snippets()->addConfigDir(
            $this->Path() . 'Snippets/'
        );
    }

    /**
     * Registers the js files in less compiler
     * used by AmazonPay and PaypalExpress and CreditCard jquery plugins
     */
    public function registerJavascript()
    {
        $this->subscribeEvent(
            'Theme_Compiler_Collect_Plugin_Javascript',
            'addJsFiles'
        );
    }

    /**
     * Callback method for Event "Theme_Compiler_Collect_Plugin_Javascript"
     * adds
     * @param Enlight_Event_EventArgs $args
     * @return ArrayCollection
     */
    public function addJsFiles(Enlight_Event_EventArgs $args)
    {
        $jsFiles = [
            $this->Path() . 'Views/responsive/frontend/_resources/javascript/fatchipFCSAmazon.js',
            $this->Path() . 'Views/responsive/frontend/_resources/javascript/fatchipFCSKlarnaPayments.js',
            $this->Path() . 'Views/responsive/frontend/_resources/javascript/fatchipFCSAmazonSCA.js',
            $this->Path() . 'Views/responsive/frontend/_resources/javascript/fatchipFCSPaypalExpress.js',
            $this->Path() . 'Views/responsive/frontend/_resources/javascript/fatchipFCSCreditCard.js',
        ];
        return new ArrayCollection($jsFiles);
    }

    /**
     * Register the custom model dir
     */
    protected function registerCustomModels()
    {
        Shopware()->Loader()->registerNamespace(
            'Shopware\CustomModels',
            $this->Path() . 'Models/'
        );
    }

    /**
     * Registers namespaces used by the plugin
     * and its components
     */
    private function registerComponents()
    {
        Shopware()->Loader()->registerNamespace(
            'Shopware\Plugins\FatchipFCSPayment',
            $this->Path()
        );

        Shopware()->Loader()->registerNamespace(
            'Fatchip',
            $this->Path() . 'Components/Api/lib/'
        );
    }

    /**
     * This callback function is triggered at the very beginning of the dispatch process and allows
     * us to register additional events on the fly. This way you won't ever need to reinstall you
     * plugin for new events - any event and hook can simply be registered in the event subscribers
     *
     *
     * @param Enlight_Event_EventArgs $args
     *
     */
    public function onStartDispatch(Enlight_Event_EventArgs $args)
    {
        $this->registerComponents();
        $this->registerSnippets();

        $container = Shopware()->Container();

        //TODO: deactivate subscribers if payment method is inactive
        $subscribers = [
            [Service::class, null],
            [ControllerPath::class, $this->Path()],
            [TemplateRegistration::class, $this],
            [Checkout::class, null],
            [KlarnaPayments::class, null],
            [Shopware\Plugins\FatchipFCSPayment\Subscribers\Frontend\RiskManagement::class, $container],
            [Logger::class, null],
            [Templates::class, null],
            [Debit::class, null],
            [OrderList::class, null],
            [EasyCredit::class, null],
            [AmazonPay::class, null],
            [PaypalExpress::class, null],
            [CreditCard::class, null],
            [AfterPay::class, null],
            [AmazonPayCookie::class, null],
            [PaypalExpressCookie::class, null]
        ];

        foreach ($subscribers as $subscriberClass) {
            $subscriber = new $subscriberClass[0]($subscriberClass[1]);
            $this->Application()->Events()->addSubscriber($subscriber);
        }
    }

    /**
     * Returns plugin info
     *
     * @return array
     * @throws Exception
     */
    public function getInfo()
    {
        $logo = base64_encode(file_get_contents(__DIR__ . DIRECTORY_SEPARATOR . 'logo.png'));
        $info = json_decode(file_get_contents(__DIR__ . DIRECTORY_SEPARATOR . 'plugin.json'), true);
        if (!$info) {
            throw new Exception('The plugin has an invalid version file.');
        }

        $info['label'] = $info['label']['de'];
        $info['version'] = $info['currentVersion'];
        $info['description'] = '<p><img alt="Logo" src="data:image/png;base64,' . $logo . '" /></p>'
            . file_get_contents(__DIR__ . '/description.txt');

        return $info;
    }

    /**
     * Returns the current plugin version number
     *
     * @return string
     * @throws Exception
     */
    public function getVersion()
    {
        return $this->getInfo()['currentVersion'];
    }

    /**
     * Returns the plugin display name
     *
     * @return string
     * @throws Exception
     */
    public function getLabel()
    {
        return $this->getInfo()['label']['de'];
    }

    /**
     * Returns the plugin solution name
     *
     * @return string
     * @throws Exception
     */
    public function getSolutionName()
    {
        return $this->getInfo()['solution_name'];
    }

    /**
     * Returns the capabilities of the plugin
     *
     * @return array
     */
    public function getCapabilities()
    {
        return [
            'install' => true,
            'update' => true,
            'enable' => true,
            'secureUninstall' => true,
        ];
    }

    /**
     * Enable plugin method
     * @return array
     */
    public function enable()
    {
        $this->addControllersToSeoBlacklist();
        return $this->invalidateCaches(true);
    }

    /**
     * Disable plugin method
     * @return array
     */
    public function disable()
    {
        $this->removeControllersFromSeoBlacklist();
        return $this->invalidateCaches(true);
    }

    /**
     * Uninstalls the plugin
     * and removes Plugin data
     * sw base removes ini snippets, configuration,
     * menu entries and template entries
     *
     * @return array
     */
    public function uninstall()
    {
        $models = new Models();
        $models->removeModels();
        $riskRules = new RiskRules();
        $riskRules->removeRiskRules();
        $this->removeBackendSnippets();
        return ['success' => true];
    }

    /**
     * Secure uninstall plugin method
     *
     * does not remove Plugin data only subscribers,
     * cronjobs, config elemtents
     * @return array
     */
    public function secureUninstall()
    {
        return ['success' => true];
    }

    /**
     * Updates the plugin
     *
     * @param string $oldVersion
     * @return array
     */
    public function update($oldVersion)
    {
        $this->removeOldPayments();

        $forms = new Forms();
        $attributes = new Attributes();
        $payments = new Payments();

        $forms->createForm();
        $this->addFormTranslations(\Fatchip\FCSPayment\CTPaymentConfigForms::formTranslations);
        $attributes->createAttributes();
        $payments->createPayments();

        $this->addControllersToSeoBlacklist();
        if (! $this->cronjobExists()) {
            $this->createCronJob(self::cronjobName, 'cleanupCTPaymentLogs', 86400, true);
            $this->subscribeEvent('Shopware_CronJob_CleanupCTPaymentLogs', 'cleanupCTPaymentLogs');
        }
        try {
            $this->checkOpenSSLSupport();
        } catch (Exception $e) {
            Shopware()->Container()->get('shopware.snippet_database_handler')
                ->loadToDatabase($this->Path() . '/Snippets/');
            $message = Shopware()->Snippets()
                ->getNamespace('frontend/FatchipFCSPayment/translations')
                ->get('errorBlowfishNotSupported');
            return ['success' => true, 'message' => $message];
        }

        return ['success' => true];
    }

    /**
     * invalidates caches
     * @param bool $return
     * @return array
     */
    protected function invalidateCaches($return)
    {
        return [
            'success' => $return,
            'invalidateCache' => [
                'backend',
                'config',
                'proxy',
                'theme',
            ],
        ];
    }

    /**
     * this wrapper is used for logging Server requests and responses to our shopware model
     *
     * @param $requestParams
     * @param $payment Fatchip\FCSPayment\CTPaymentMethod
     * @param $requestType
     * @param $url
     *
     * @return CTResponse
     */
    public function callComputopService($requestParams, $payment, $requestType, $url)
    {
        $log = new FatchipFCSApilog();
        $log->setPaymentName($payment::paymentClass);
        $log->setRequest($requestType);
        $log->setRequestDetails(json_encode($requestParams));
        $response = $payment->callComputop($requestParams, $url);
        $log->setTransId($response->getTransID());
        $log->setPayId($response->getPayID());
        $log->setXId($response->getXID());
        $log->setResponse($response->getStatus());
        $log->setResponseDetails(json_encode($response->toArray()));
        try {
            Shopware()->Models()->persist($log);
            Shopware()->Models()->flush($log);
        } catch (Exception $e) {
            $logger = new Logger();
            $logger->logError('Unable to save API Log', [
                'error' => $e->getMessage()
            ]);
        }
        return $response;
    }

    /**
     * this wrapper is used for logging Redirectrequests and responses to our shopware model
     *
     * @param array $requestParams
     * @param string $paymentName
     * @param string $requestType
     * @param CTResponse $response
     *
     * @return void
     * @throws Exception
     */
    public function logRedirectParams($requestParams, $paymentName, $requestType, $response)
    {
        // fix wrong amount is logged PHP Version >= 7.1 see https://stackoverflow.com/questions/42981409/php7-1-json-encode-float-issue/43056278
        $requestParams['amount'] = (string) $requestParams['amount'];
        $log = new FatchipFCSApilog();
        $log->setPaymentName($paymentName);
        $log->setRequest($requestType);
        $log->setRequestDetails(json_encode($requestParams));
        $log->setTransId($response->getTransID());
        $log->setPayId($response->getPayID());
        $log->setXId($response->getXID());
        $log->setResponse($response->getStatus());
        $log->setResponseDetails(json_encode($response->toArray()));
        Shopware()->Models()->persist($log);
        Shopware()->Models()->flush($log);
    }

    public function removeOldPayments()
    {
        $oldPayments = [
            'fatchip_firstcash_klarna_installment',
            'fatchip_firstcash_klarna_invoice',
            'fatchip_firstcash_afterpay_installment',
            'fatchip_firstcash_klarna_pay_now',
        ];

        foreach ($oldPayments as $payment) {
            $this->removePayment($payment);
        }
    }

    /**
     * Remove payment instance
     *
     * @param string $paymentName
     *
     */
    public function removePayment($paymentName)
    {
        $payment = $this->Payments()->findOneBy(
            array(
                'name' => $paymentName
            )
        );
        if ($payment === null) {
            // do nothing
        } else {
            try {
                Shopware()->Models()->remove($payment);
                Shopware()->Models()->flush();
            } catch (Exception $e) {
                $logger = new Logger();
                $logger->logError('Unable to remove payment ' . $paymentName, [
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    public function removeBackendSnippets()
    {
        $builder = Shopware()->Models()->createQueryBuilder();
        $builder->delete('Shopware\Models\Snippet\Snippet', 'snippets')
            ->where('snippets.namespace = :namespace1')
            ->setParameter('namespace1', 'backend/fcfcs__order/main')
            ->orwhere('snippets.namespace = :namespace1')
            ->setParameter('namespace1', 'backend/fcfcs_order/main')
            ->orwhere('snippets.namespace = :namespace1')
            ->setParameter('namespace1', 'frontend/checkout/firstcash_easycredit_confirm')
            ->orwhere('snippets.namespace = :namespace1')
            ->setParameter('namespace1', 'frontend/checkout/firstcash_easycredit_confirm')
            ->orwhere('snippets.namespace = :namespace1')
            ->setParameter('namespace1', 'backend/fatchip_fcs_apilog/main');
        $result = $builder->getQuery()->execute();

        $builder->delete('Shopware\Models\Snippet\Snippet', 'snippets')
            ->where('snippets.namespace = :namespace1')
            ->setParameter('namespace1', 'backend/index/view/main');
        $builder->andWhere('snippets.name = :name1')
            ->setParameter('name1', 'FatchipFCSApilog/index');
        $result = $builder->getQuery()->execute();
    }

    /**
     * used by Cleanup Firstcash Payment Logs Cronjob
     * deletes all entries in
     * s_plugin_fatchip_firstcash_api_log
     * older than 2 years
     *
     * @return void
     */
    public function cleanupPaymentLogs()
    {
        $builder = $this->getLogQuery();
        $result = $builder->getQuery()->getArrayResult();
    }

    /**
     * returns sql base query
     *
     * @return \Doctrine\ORM\QueryBuilder
     */
    public function getLogQuery()
    {
        $twoYearsAgo = date('Y-m-d H:i:s', strtotime('-2 years'));

        $builder = Shopware()->Models()->createQueryBuilder();
        $builder->delete()
            ->from('Shopware\CustomModels\FatchipFCSApilog\FatchipFCSApilog', 'log')
            ->where($builder->expr()->lte('log.creationDate', "'" . $twoYearsAgo . "'"));
        return $builder;
    }

    /**
     * adds all payment controllers to seo blacklist
     * this will set noindex, nofollow in the meta header
     *
     * @return void
     */
    public function addControllersToSeoBlacklist()
    {
        $controllerBlacklist = $this->getControllerBlacklist();
        if (array_diff(self::pluginControllers, $controllerBlacklist)) {
            $newControllerBlacklist = array_merge(self::pluginControllers, $controllerBlacklist);
            $this->updateBlackList($newControllerBlacklist, true);
        }
    }

    /**
     * adds removes all payment controllers from the seo blacklist
     *
     * @return void
     */
    public function removeControllersFromSeoBlacklist()
    {
        $controllerBlacklist = $this->getControllerBlacklist();
        if (array_diff($controllerBlacklist, self::pluginControllers))
        {
            $newControllerBlacklist = array_diff($controllerBlacklist, self::pluginControllers);
            $this->updateBlackList($newControllerBlacklist, false);
        }
    }

    /**
     * @return array
     */
    private function getControllerBlacklist()
    {
        $mgr = $this->get(Shopware\Components\CacheManager::class);
        $mgr->clearByTag(Shopware\Components\CacheManager::CACHE_TAG_CONFIG);
        $config = $this->get(\Shopware_Components_Config::class);
        $controllerBlacklist = preg_replace('#\s#', '', $config[self::blacklistConfigVar]);
        return explode(',', $controllerBlacklist);
    }

    /**
     * updates the seo blacklist in database
     * @param array $blackList
     * @param bool $install set to false on uninstall
     * @return void
     */
    private function updateBlackList($blackList, $install)
    {
        $result = Shopware()->Db()->executeQuery(
            "UPDATE s_core_config_values SET value= :value WHERE element_id = (SELECT id FROM s_core_config_elements WHERE name = :name)",
            [
                ':value' => serialize(implode(',', $blackList)),
                ':name' => self::blacklistDBConfigVar
            ]
        );

        if ($result->rowCount() === 0 && $install) {
            $result = Shopware()->Db()->executeQuery(
                "INSERT INTO s_core_config_values (`element_id`, `value`, `shop_id`) VALUES ((SELECT id FROM s_core_config_elements WHERE name = :name), :value, 1)",
                [
                    ':name' => self::blacklistDBConfigVar,
                    ':value' => serialize(implode(',', $blackList))
                ]
            );
        }
    }

    /**
     * @return bool
     */
    private function cronjobExists()
    {
        $query = "SELECT * from s_crontab where name ='" . self::cronjobName . "'";
        $result = Shopware()->Db()->executeQuery($query);

        if ($result->rowCount() === 0) {
            return false;
        }
        return true;
    }

    /**
     * @return bool
     */
    public function checkOpenSSLSupport()
    {
        $ciphers = openssl_get_cipher_methods(false);
        $isBlowfishSupported = in_array(Encryption::blowfishCipher, $ciphers);
        $isAES128Supported = in_array(Encryption::aes128Cipher, $ciphers);
        $isAES192Supported = in_array(Encryption::aes192Cipher, $ciphers);
        $isAES256Supported = in_array(Encryption::aes256Cipher, $ciphers);

        $pwLength = strlen($this->blowfishPassword);
        if ($pwLength <= 16) {
            $keyLength = 16;
        } else if ($pwLength <= 24) {
            $keyLength = 24;
        } else {
            $keyLength = 32;
        }

        if ($this->encryption === 'blowfish' && !$isBlowfishSupported) {
            throw new Exception('Openssl ' . Encryption::blowfishCipher . ' Encryption is not supported on your platform');
            return false;
        }
        if ($keyLength === 16 && $this->encryption === 'aes' && !$isAES128Supported) {
            throw new Exception('Openssl ' . Encryption::aes128Cipher . ' Encryption is not supported on your platform');
            return false;
        }

        if ($keyLength === 24 && $this->encryption === 'aes' && !$isAES192Supported) {
            throw new Exception('Openssl ' . Encryption::aes192Cipher . ' Encryption is not supported on your platform');
            return false;
        }

        if ($keyLength === 32 && $this->encryption === 'aes' && !$isAES256Supported) {
            throw new Exception('Openssl ' .Encryption::aes256Cipher . ' Encryption is not supported n your platform');
            return false;
        }
    }
}
