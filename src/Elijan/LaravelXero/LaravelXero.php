<?php namespace Elijan\LaravelXero;

use Illuminate\Config\Repository;

use Monolog\Logger;
use Monolog\Handler\StreamHandler;

class LaravelXero {



    private $config;

    private $invoice;

    private $xero;
    private $log;

    public function __construct(Repository $config){
        $this->log =  new Logger('paypal-adaptive');
        $this->log->pushHandler(new StreamHandler(storage_path('logs').'/xero-service.log', Logger::INFO));


        $this->config  = $config->get("laravel-xero::config");

        $this->log->addInfo("Init Connection....".serialize($this->config));

        $this->xero = new \XeroPHP\Application\PrivateApplication($this->config);



    }



    /**
     * @param \Dealsealer\Api\Accounts $plan_manager
     * @param $reference_id
     */
    public function createInvoiceAccRec(\Dealsealer\Api\Accounts $account, $reference_id){

        $this->log->addInfo("Create Invoice Rec....");

        $contact = $this->validateInvoice($account, \XeroPHP\Models\Accounting\Invoice::INVOICE_TYPE_ACCREC);
        if($contact == false){

            $contact =  $this->createAccount($account);

        }

        $this->invoice  =   new \XeroPHP\Models\Accounting\Invoice();
        $this->invoice->setStatus('AUTHORISED');
        $this->invoice->setType(\XeroPHP\Models\Accounting\Invoice::INVOICE_TYPE_ACCREC);
        $this->invoice->setContact($contact);
        $this->invoice->setReference($reference_id);
    }


    /**
     * @param \Dealsealer\Api\Accounts $plan_manager
     * @param $reference_id\
     */
    public function createInvoiceAccPay(\Dealsealer\Api\Accounts $account, $reference_id){

        $this->log->addInfo("Create Invoice Pay....");

        $contact = $this->validateInvoice($account, \XeroPHP\Models\Accounting\Invoice::INVOICE_TYPE_ACCPAY);

        if($contact == false){
            //create an account

            $contact = $this->createAccount($account);

        }


        $this->invoice  =   new \XeroPHP\Models\Accounting\Invoice();
        $this->invoice->setStatus(\XeroPHP\Models\Accounting\Invoice::INVOICE_STATUS_AUTHORISED);
        $this->invoice->setType(\XeroPHP\Models\Accounting\Invoice::INVOICE_TYPE_ACCPAY);

        $this->invoice->setContact($contact);
        $this->invoice->setReference($reference_id);
    }



    public  function addItemToInvoice(\Dealsealer\Api\BundleInvoices $invoice){


        $item = $invoice->item;

        if( !$this->invoice instanceof \XeroPHP\Models\Accounting\Invoice){

            throw new \Exception('Invoice Not created');
        }




        $xero_item = $this->validateItem($item);


        if($xero_item === false) {

            $xero_item = new \XeroPHP\Models\Accounting\Item();

            $xero_item->setCode($item->xero_code);
            $xero_item->setName($item->item->name);
            $xero_item->setIsTrackedAsInventory(false);


            $sales = new \XeroPHP\Models\Accounting\Item\Sale();
            $sales->setUnitPrice($this->getUnitAmount($invoice));
            $sales->setAccountCode('200');

            $xero_item->addSalesDetail($sales);

            $response = $this->xero->save($xero_item);
            $response = $response->getElements()[0];


            $item->xero_guid = $response['ItemID'];
            $item->save();
        }


        $line_item =  new \XeroPHP\Models\Accounting\Invoice\LineItem();
        $line_item->setItemCode($item->xero_code);
        $line_item->setDescription($item->item->name);
        $line_item->setAccountCode('200');
        $line_item->setQuantity($invoice->quantity/100);

        $line_item->setUnitAmount($this->getUnitAmount($invoice));

        $this->invoice->addLineItem($line_item);
        $date = new \DateTime(date('Y-m-d', strtotime('+30 days')));
        $this->invoice->setDueDate($date);

    }

    /**
     * @param \Dealsealer\Api\Accounts $account
     *
     */
    public function createAccount(\Dealsealer\Api\Accounts $account){

            $contact = new \XeroPHP\Models\Accounting\Contact();
            $contact->setName($account->business_name);
            $contact->setEmailAddress($account->user->email);
            $contact->setFirstName($account->first_name);
            $contact->setLastName($account->last_name);
            $response = $this->xero->save($contact);

            $response = $response->getElements()[0];

            $account->xero_guid = $response['ContactID'];
            $account->save();

            return $contact;


    }

    /**
     * @return null
     * @throws \XeroPHP\Exception
     */
    public function saveInvoice(){

        $this->log->addInfo("Svaing Ivoice". serialize($this->invoice));

        $result = $this->xero->save($this->invoice);

        return $result;
    }


    private function validateItem(\Dealsealer\Api\BundleItems $item){

        try {

            //check if item exist in the invetory
            return  $this->xero->loadByGUID('Accounting\Item', $item->xero_code);


        }catch(\XeroPHP\Remote\Exception\NotFoundException $e){



        }
        return false;
    }

    /**
     * @param \Dealsealer\Api\BundleItems $item
     */
    private function getUnitAmount(\Dealsealer\Api\BundleInvoices $invoice){

        $item = $invoice->item;
        $unit_cost  = $invoice->price;
        $commission =  $item->xero_percentage/100;

        if( $this->invoice->getType()== \XeroPHP\Models\Accounting\Invoice::INVOICE_TYPE_ACCPAY){

            $unit_cost =   ($unit_cost-  (($unit_cost * $commission)/100))/100;

            $this->log->addInfo("Adding Unit Cost $unit_cost ");

        }

        return $unit_cost;
    }

    /**
     * @param \Dealsealer\Api\Accounts $plan_manager
     * @param $type
     * @return mixed
     */
    private function validateInvoice(\Dealsealer\Api\Accounts $account, $type){


        if($type == \XeroPHP\Models\Accounting\Invoice::INVOICE_TYPE_ACCREC && $account->user->type !== \Dealsealer\Api\User::PM_MANAGER){

            $this->log->addInfo("User not a Plan Manager....".serialize($account));
            throw new \Exception("User not a Plan Manager");
        }

        if($type == \XeroPHP\Models\Accounting\Invoice::INVOICE_TYPE_ACCPAY && $account->user->type !== \Dealsealer\Api\User::PROVIDER){

            $this->log->addInfo("User not a Provider....".serialize($account));
            throw new \Exception("User is not a Provider");
        }


        if($account->xero_guid){

            $this->log->addInfo("try to get the account".serialize($account));
            try {
                return $this->xero->loadByGUID('Accounting\Contact', $account->xero_guid);

            }catch(\XeroPHP\Remote\Exception\NotFoundException $e){

            }
        }

        $this->log->addInfo("User des not exist".serialize($account));
        return false;
    }

}