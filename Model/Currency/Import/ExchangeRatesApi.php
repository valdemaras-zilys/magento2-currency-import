<?php
/**
 *
 */
namespace Raguvis\CurrencyImport\Model\Currency\Import;

/**
 * Class ExchangeRatesApi
 * @package Raguvis\CurrencyImport\Model\Currency\Import
 */
class ExchangeRatesApi extends \Magento\Directory\Model\Currency\Import\AbstractImport
{
    /**
     * @var string
     */
    protected $_currencyConverterURL = 'https://api.exchangeratesapi.io/latest?base={{CURRENCY_FROM}}&symbols={{CURRENCY_TO}}';

    /**
     * Http Client Factory
     *
     * @var \Magento\Framework\HTTP\ZendClientFactory
     */
    protected $httpClientFactory;

    /**
     * Core scope config
     *
     * @var \Magento\Framework\App\Config\ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * Initialize dependencies
     *
     * @param \Magento\Directory\Model\CurrencyFactory $currencyFactory
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     * @param \Magento\Framework\HTTP\ZendClientFactory $httpClientFactory
     */
    public function __construct(
        \Magento\Directory\Model\CurrencyFactory $currencyFactory,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Framework\HTTP\ZendClientFactory $httpClientFactory
    ) {
        parent::__construct($currencyFactory);
        $this->scopeConfig = $scopeConfig;
        $this->httpClientFactory = $httpClientFactory;
    }

    /**
     * {@inheritdoc}
     */
    public function fetchRates()
    {
        $data = [];
        $currencies = $this->_getCurrencyCodes();
        $defaultCurrencies = $this->_getDefaultCurrencyCodes();

        foreach ($defaultCurrencies as $currencyFrom) {
            if (!isset($data[$currencyFrom])) {
                $data[$currencyFrom] = [];
            }
            $data = $this->convertBatch($data, $currencyFrom, $currencies);
            ksort($data[$currencyFrom]);
        }
        return $data;
    }

    /**
     * Return currencies convert rates in batch mode
     *
     * @param array $data
     * @param string $currencyFrom
     * @param array $currenciesTo
     * @return array
     */
    private function convertBatch($data, $currencyFrom, $currenciesTo)
    {
        $url = $this->getRatesURL($currencyFrom,$currenciesTo);


        set_time_limit(0);
        try {
            $response = $this->getServiceResponse($url);
        } finally {
            ini_restore('max_execution_time');
        }

        foreach ($currenciesTo as $currencyTo) {
            if ($currencyFrom == $currencyTo) {
                $data[$currencyFrom][$currencyTo] = $this->_numberFormat(1);
            } else {
                if (empty($response['rates'][$currencyTo])) {
                    $this->_messages[] = __('We can\'t retrieve a rate from %1 for %2.', $url, $currencyTo);
                    $data[$currencyFrom][$currencyTo] = null;
                } else {
                    $data[$currencyFrom][$currencyTo] = $this->_numberFormat(
                        (double)$response['rates'][$currencyTo]
                    );
                }
            }
        }
        return $data;
    }

    /**
     * Get API response
     *
     * @param string $url
     * @param int $retry
     * @return array
     */
    private function getServiceResponse($url, $retry = 0)
    {
        /** @var \Magento\Framework\HTTP\ZendClient $httpClient */
        $httpClient = $this->httpClientFactory->create();
        $response = [];

        try {
            $jsonResponse = $httpClient->setUri(
                $url
            )->setConfig(
                [
                    'timeout' => $this->scopeConfig->getValue(
                        'currency/foreignexchangerates/timeout',
                        \Magento\Store\Model\ScopeInterface::SCOPE_STORE
                    ),
                ]
            )->request(
                'GET'
            )->getBody();

            $response = json_decode($jsonResponse, true);
        } catch (\Exception $e) {
            if ($retry == 0) {
                $response = $this->getServiceResponse($url, 1);
            }
        }
        return $response;
    }

    /**
     * {@inheritdoc}
     */
    protected function _convert($currencyFrom, $currencyTo)
    {
    }

    /**
     * @param $currencyFrom
     * @param $currenciesTo
     * @return mixed
     */
    private function getRatesURL($currencyFrom, $currenciesTo){
        $urlTemplate =  $this->scopeConfig->getValue(
            'currency/foreignexchangerates/reates_url_template',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );

        $currenciesStr = implode(',', $currenciesTo);
        $url = str_replace('{{CURRENCY_FROM}}', $currencyFrom, $urlTemplate);
        $url = str_replace('{{CURRENCY_TO}}', $currenciesStr, $url);

        return $url;
    }
}