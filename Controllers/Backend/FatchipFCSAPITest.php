<?php

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
 * PHP version 5.6, 7.0 , 7.1
 *
 * @category   Payment
 * @package    FatchipFCSPayment
 * @subpackage Controllers/Backend
 * @author     FATCHIP GmbH <support@fatchip.de>
 * @copyright  2018 First Cash Solution
 * @license    <http://www.gnu.org/licenses/> GNU Lesser General Public License
 * @link       https://www.firstcashsolution.de/
 */

use Fatchip\FCSPayment\CTPaymentService;
use Fatchip\FCSPayment\CTAPITestService;

/**
 * Shopware_Controllers_Backend_FatchipFCSIdeal
 *
 *  gets/updates ideal issuer list.
 */
class Shopware_Controllers_Backend_FatchipFCSAPITest extends Shopware_Controllers_Backend_ExtJs
{
    /**
     * FatchipFCSpayment Plugin Bootstrap Class
     * @var \Shopware_Plugins_Frontend_FatchipFCSPayment_Bootstrap
     */
    private $plugin;

    /**
     * FatchipFCSPayment Configuration
     * @var array
     */
    private $config;

    /**
     * Payment Service
     * @var CTPaymentService
     */
    private $paymentService;

    /**
     * {@inheritdoc}
     */
    public function init()
    {
        $this->plugin = Shopware()->Plugins()->Frontend()->FatchipFCSPayment();
        $this->config = $this->plugin->Config()->toArray();
        $this->paymentService = Shopware()->Container()->get('FatchipFCSPaymentApiClient');
        parent::init();
    }

    /**
     * updates ideal bank data from firstcash.
     *
     * assigns error and count of updated items to view
     *
     * @return void
     */
    public function apiTestAction()
    {
        $service = new CTAPITestService($this->config);
        try {
            $success = $service->doAPITest();
        } catch (Exception $e) {
            $success = false;
        }

        if ($success) {
            $this->View()->assign(['success' => true]);
        } else {
            $this->View()->assign(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * prevents CSRF Token errors
     * @return array
     */
    public function getWhitelistedCSRFActions()
    {
        $csrfActions = ['apiTestAction'];

        return $csrfActions;
    }
}
