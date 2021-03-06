<?php
namespace SofortCom\Controller\Component;

use Base64Url\Base64Url;
use Cake\Core\Configure;
use Cake\Controller\Component;
use Cake\Event\Event;
use Cake\ORM\TableRegistry;
use Cake\Routing\Router;
use Cake\Utility\Security;
use Sofort\SofortLib\Notification;
use Sofort\SofortLib\Sofortueberweisung;
use Sofort\SofortLib\TransactionData;
use SofortCom\Exceptions;

class SofortlibComponent extends Component
{
    private $Sofortueberweisung;
    private $Config;
    private $protectedMethods = ['setnotificationurl', 'sendrequest'];
    private $notifyOnReasons = ['loss', 'pending', 'received', 'refunded'];
    private $shop_id;
    private $Notifications;
    private $encryptionKey;

    /** @var \Controller */
    private $Controller;

    public function initialize(array $config): void
    {
        parent::initialize($config);
        $this->Config = Configure::read('SofortCom');
        $this->encryptionKey = $this->Config['encryptionKey'];
        $this->Sofortueberweisung = new Sofortueberweisung($this->Config['configkey']);
        if (!empty($this->Config['currency']))
            $this->setCurrencyCode($this->Config['currency']);
        $this->Notifications = TableRegistry::getTableLocator()->get('SofortCom.Notifications');
        $this->ShopTransactions = TableRegistry::getTableLocator()->get('SofortCom.ShopTransactions');
    }

    public function startup($event)
    {
        $this->Controller = $event->getSubject();
    }

    /**
     * Forward function calls to Sofortueberweisung
     * @param string $name Function name
     * @param array $arguments Function arguments
     * @return type mixed
     * @throws \InvalidArgumentException when trying to call setnotificationurl() or sendrequest()
     */
    public function __call(string $name, array $arguments)
    {
        if(method_exists($this->Sofortueberweisung, $name))
        {
            if (in_array(strtolower($name), $this->protectedMethods))
                throw new \InvalidArgumentException("Calling $name is not allowed.");

            return call_user_func_array([$this->Sofortueberweisung, $name], $arguments);
        }
    }

    /**
     * Your shop or order id, or whatever is associated with the generated
     * Sofort.com transaction number. This number will be forwarded to the
     * notifyCallback function.
     *
     * @param int $id unsigned int with your shop or order id
     */
    public function setShopId($id)
    {
        $this->shop_id = $id;
    }

    protected function ParseNotification($rawPostStream)
    {
        $notification = new Notification();
        $success = $notification->getNotification(
                file_get_contents($rawPostStream)
        );
        if ($success === false)
            throw new Exceptions\NotificationException($notification);
        return $notification;
    }

    protected function BuildTransactionData()
    {
        return new TransactionData($this->Config['configkey']);
    }

    public function HandleNotifyUrl($eShopId, $notifyOn, $ip, $rawPostStream = 'php://input')
    {
        $shop_id = Security::decrypt(
                Base64Url::decode($eShopId),
                $this->encryptionKey);

        $notification = $this->ParseNotification($rawPostStream);
        $transaction = $notification->getTransactionId();
        $time = $notification->getTime();

        $notification = $this->Notifications->Add($transaction, $notifyOn, $time, $ip);

        $transactionData = $this->BuildTransactionData();
        $transactionData->addTransaction($transaction);
        $transactionData->setApiVersion('2.0');
        $transactionData->sendRequest();
        $transactionData->setNumber(1);

        $event = new Event('SofortCom.Notify', $this,
        [
            'args' => [
                'shop_id' => $shop_id,
                'notifyOn' =>  $notifyOn,
                'transaction' => $transaction,
                'time' => $time,
                'data' => $transactionData,
            ]
        ]);

        $notification->status = $transactionData->getStatus();
        $notification->status_reason = $transactionData->getStatusReason();
        $this->Notifications->save($notification);

        $this->Controller->getEventManager()->dispatch($event);

        $result = $event->getResult();
        if(empty($result['handled']))
            throw new Exceptions\UnhandledNotificationException('Payment notification is unhandled');
    }

    /**
     * Calls Sofortueberweisung::sendRequest and redirects the buyer to
     * the payment url.
     * @throws SofortLibException when Sofortueberweisung returns an error
     * @throws \InvalidArgumentException when no shop_id has been set.
     */
    public function PaymentRedirect()
    {
        if (empty($this->shop_id))
            throw new \UnexpectedValueException("No shop_id set.");

        $eShopId = rawurlencode(Base64Url::encode(Security::encrypt($this->shop_id, $this->encryptionKey)));
        if (empty($eShopId))
            throw new \UnexpectedValueException("Encrypted shop_id is empty");

        $urlOptions = [
            '_method' => 'POST',
            'controller' => 'PaymentsNotification',
            'action' => 'Notify',
            'plugin' => 'SofortCom',
            'eShopId' => $eShopId];
        foreach ($this->notifyOnReasons as $notifyOn)
        {
            $urlOptions['notifyOn'] = $notifyOn;
            $notificationUrl = Router::url($urlOptions, true);
            $this->Sofortueberweisung->setNotificationUrl($notificationUrl, $notifyOn);
        }

        $this->Sofortueberweisung->sendRequest();
        if ($this->Sofortueberweisung->isError())
        {
            $error = $this->Sofortueberweisung->getError();
            $exception = new Exceptions\RequestException($error);
            $exception->errors = $this->Sofortueberweisung->getErrors();
            throw $exception;
        }

        $transaction = $this->Sofortueberweisung->getTransactionId();
        $payment_url = $this->Sofortueberweisung->getPaymentUrl();

        $this->ShopTransactions->Add($transaction, $this->shop_id);

        $event = new Event('SofortCom.NewTransaction', $this,
        [
            'args' => [
                'transaction' => $transaction,
                'payment_url' => $payment_url
            ]
        ]);
        $this->Controller->getEventManager()->dispatch($event);

        return $this->Controller->redirect($payment_url);
    }

    /**
     *
     * @param type $amount in cents
     * @return type amount plus neutralization amount so when Sofort.com subtract it's fee
     * the intended amount will be received.
     * @throws \InvalidArgumentException if SofortCom conditions are not set in config
     */
    public static function NeutralizeFee($amount)
    {
        $conditions = self::_getConditionsFromConfig();
        return $amount + ceil(self::CalculateFee($amount) / ( 1 - $conditions['fee_relative'] ));
    }

    /**
     *
     * @param type $amount in cents
     * @return type Sofort.com fee in cents based on amount
     * @throws \InvalidArgumentException if SofortCom conditions are not set in config
     */
    public static function CalculateFee($amount)
    {
        $conditions = self::_getConditionsFromConfig();
        return ceil($amount * $conditions['fee_relative'] + $conditions['fee']);
    }

    private static function _getConditionsFromConfig()
    {
        $config = Configure::read('SofortCom');
        if (empty($config['conditions']))
            throw new \InvalidArgumentException('Missing SofortCom conditions.');

        $conditions = $config['conditions'];
        if (!isset($conditions['fee']) || !isset($conditions['fee_relative']))
            throw new \InvalidArgumentException('Missing SofortCom condition fees.');

        return $conditions;
    }
}