<?php namespace Elijan\LaravelXero;

use Aws\CloudFront\Exception\Exception;
use GuzzleHttp\Subscriber\Redirect;
use Illuminate\Config\Repository;
use Illuminate\Support\Facades\Session;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use XeroPHP\Models\Accounting\Account;
use \XeroPHP\Remote\URL;
use \XeroPHP\Remote\Request;
use \Illuminate\Support\Facades\Auth as Auth;

class LaravelXero {



    private $config;

    private $invoice;

    private $xero;
    private $log;

    public function __construct(Repository $config){
        $file_name = storage_path('logs').'/xero-service-'.date('y-m-d').'.log';
        $this->log =  new Logger('xero-log');
        $this->log->pushHandler(new StreamHandler($file_name, Logger::INFO, true, 0777));



        $this->config  = $config->get("laravel-xero::config");

        $this->log->addInfo("Init Connection....".serialize($this->config));

        $this->xero = new \XeroPHP\Application\PublicApplication($this->config);

    }


    function setOAuthSession($token, $secret, $expires = null){

       Session::put('oauth',['token' => $token, 'token_secret' => $secret,'expires' => time()+$expires]);
       Session::save();

    }

    function getOAuthSession($request = true){

        //If it doesn't exist or is expired, return null, but just for request
        $oauth = \Session::get('oauth');
        if (!$oauth || $oauth['expires'] == null || ($oauth['expires'] !== null && $oauth['expires'] <= time()))
             return null;


        return $oauth;
    }


    /**
     * @throws \XeroPHP\Exception
     *
     * verify Auth token
     */

    public function verify(){

        $oauth_session = Session::get('oauth');

        $this->xero->getOAuthClient()
            ->setToken($oauth_session['token'])
            ->setTokenSecret($oauth_session['token_secret']);

        if(\Input::get('oauth_verifier')){

            $this->xero->getOAuthClient()->setVerifier(\Input::get('oauth_verifier'));
            $url = new URL($this->xero, URL::OAUTH_ACCESS_TOKEN);

            $request = new Request($this->xero, $url);

            $request->send();
            $oauth_response = $request->getResponse()->getOAuthResponse();

            $this->setOAuthSession($oauth_response['oauth_token'], $oauth_response['oauth_token_secret'], $oauth_response['oauth_expires_in']);
            return true;
        }

        if($this->getOAuthSession()==null){

            return false;
        }
        return true;
    }

    /**
     * @return mixed
     */
    public function authorize(){

        //check if auth user xero_gui exist


        if(null === $oauth_session = $this->getOAuthSession() ){

            $url = new URL($this->xero, URL::OAUTH_REQUEST_TOKEN);
            $request = new Request($this->xero, $url);

            //Here's where you'll see if your keys are valid.  You can catch a BadRequestException.
            try {
                $request->send();
            } catch (\Exception $e){

                return ['error'=>true, 'message'=>$e->getMessage()];

            }

            $oauth_response = $request->getResponse()->getOAuthResponse();


            $this->setOAuthSession($oauth_response['oauth_token'], $oauth_response['oauth_token_secret']);

            return ['error'=>false, 'url'=>$this->xero->getAuthorizeURL($oauth_response['oauth_token'])];
            exit;
            //return Response::json(['auth'=>false, 'url'=>$this->xero->getAuthorizeURL($oauth_response['oauth_token'])]);


        }else{

            //set auth session
            $oauth_session = Session::get('oauth');

            if($oauth_session) {
                $this->xero->getOAuthClient()
                    ->setToken($oauth_session['token'])
                    ->setTokenSecret($oauth_session['token_secret']);
            }

            return ['error'=>false, 'message'=>"<h3> You are already connected to Xero APi </h3>"];

            exit;
        }



    }



    /**
     * @param \MePlus\Models\Accounts $plan_manager
     * @param $reference_id
     *
     * goes to NDIA
     */
    public function createInvoiceAccRec(\MePlus\Models\PlanManager $planManager, $reference_id){


        $this->log->addInfo("Create Invoice Receivable....".$planManager->company->admin->xero->xero_guid);

        $contact = $this->xero->loadByGUID('Accounting\Contact',$planManager->company->admin->xero->xero_guid);



        $this->invoice  =   new \XeroPHP\Models\Accounting\Invoice();
        $this->invoice->setStatus(\XeroPHP\Models\Accounting\Invoice::INVOICE_STATUS_DRAFT);
        $this->invoice->setType(\XeroPHP\Models\Accounting\Invoice::INVOICE_TYPE_ACCREC);
        $this->invoice->setContact($contact);
        $this->invoice->setReference($reference_id);

        $this->log->addInfo("Invoice Set.......");
    }


    /**
     * @param \MePlus\Models\Accounts $plan_manager
     * @param $reference_id\
     *
     * Oyabale invice withotu meplus feee
     */
    public function createInvoiceAccPay( $account, $reference_id, $url){

        $this->log->addInfo("Create Invoice Payabale..".$account->id."....".get_class($account));



        $contact = $this->xero->loadByGUID('Accounting\Contact',$account->xero_guid);


        $this->invoice  =   new \XeroPHP\Models\Accounting\Invoice();
        $this->invoice->setStatus(\XeroPHP\Models\Accounting\Invoice::INVOICE_STATUS_DRAFT);
        $this->invoice->setType(\XeroPHP\Models\Accounting\Invoice::INVOICE_TYPE_ACCPAY);
        $this->invoice->setContact($contact);
        $this->invoice->setInvoiceNumber($reference_id);

        $this->invoice->setUrl($url);
    }


    public function getContacts($guid=null){


        try {
            if($guid===null) {
                return $this->xero->load('\\XeroPHP\\Models\\Accounting\\Contact')->execute();
            }else{
                return  $this->xero->loadByGUID('Accounting\Contact',$guid);

            }

        }catch(\XeroPHP\Remote\Exception\NotFoundException $e){

            $this->log->addInfo("Contact Not found...$guid");
            return false;
        }

    }

    public function getLiability($guid=null){


        try {
            if($guid===null) {
                return $this->xero->load('\\XeroPHP\\Models\\Accounting\\Account')->where('Class', \XeroPHP\Models\Accounting\Account::ACCOUNT_CLASS_TYPE_LIABILITY)->execute();
            }else{
                return  $this->xero->loadByGUID('Accounting\Account',$guid);

            }

        }catch(\XeroPHP\Remote\Exception\NotFoundException $e){

            $this->log->addInfo("Account Not found...$guid");
            return false;
        }

    }

    public  function addItemToInvoice(\MePlus\Models\InvoiceLineItems $item, $account_code){



        if( !$this->invoice instanceof \XeroPHP\Models\Accounting\Invoice){

            throw new \Exception('Invoice Not created');
        }


        $this->log->addInfo("Add Invoice item....");


        $line_item =  new \XeroPHP\Models\Accounting\Invoice\LineItem();
        $line_item->setItemCode($item->item_code);
        $line_item->setDescription($item->item->item->name);
        $line_item->setAccountCode($account_code);
        $line_item->setQuantity(number_format($item->quantity, 4));
        $line_item->setTaxType(\XeroPHP\Models\Accounting\ReportTaxType::AUSTRALIUM_BASEXCLUDED);
        $line_item->setUnitAmount(number_format($item->price,4));


        $this->log->addInfo("Qty....".$item->quantity);
        $this->log->addInfo("price....".$item->price);
        $this->log->addInfo("Sum....".number_format($item->quantity * $item->price,4));
        $this->log->addInfo("Sub total..".$item->subtotal);


        if($item->subtotal != (number_format($item->quantity * $item->price,4))){
            $this->log->addInfo("Not equal adding as one...");
            $line_item->setQuantity(1);
            $line_item->setUnitAmount($item->subtotal);
        }


        $this->invoice->addLineItem($line_item);

        $this->log->addInfo("added....");


    }


    public function createLiabilityAccount(\MePlus\Models\Clients $client){

        try {
            $this->log->addInfo("Creating Liability Account...", [$client->full_name]);

            $account = new \XeroPHP\Models\Accounting\Account();
            $account->setName("Client - ".$client->full_name);
            $account->setType(\XeroPHP\Models\Accounting\Account::ACCOUNT_TYPE_LIABILITY);
            $account->setCode($client->ndis_number);

            $response = $this->xero->save($account);
            $response = $response->getElements()[0];

            $account->setGUID($response['AccountID']);

            $this->log->addInfo("Creating Liability Account...");


            return $account;

        }catch(\XeroPHP\Remote\Exception\BadRequestException $e){

            $this->log->addInfo("Error Creating Liability Account....", [$e->getMessage()]);

            throw new Exception("Error Creating Liability Account....".$e->getMessage());


        }
        return false;




    }
    /**
     * @param \MePlus\Models\Clients $client
     * @return bool|\XeroPHP\Models\Accounting\Contact
     * @throws \XeroPHP\Exception
     */
    public function createClientContact(\MePlus\Models\Clients $client){

        try {
            $this->log->addInfo("Creating Client Contact...", [$client->full_name]);

            $contact = new \XeroPHP\Models\Accounting\Contact();
            $contact->setName($client->full_name);
           // $contact->setEmailAddress($company->email);
            /* $contact->setFirstName($account->first_name);
             $contact->setLastName($account->last_name);*/
            $response = $this->xero->save($contact);
            $response = $response->getElements()[0];

            $contact->setGUID($response['ContactID']);

            $this->log->addInfo("Creating Client Contact...");


            return $contact;

        }catch(\XeroPHP\Remote\Exception\BadRequestException $e){

            $this->log->addInfo("Error creating Client Contact...", [$e->getMessage()]);

            throw new Exception("Error Creating Client Contact....".$e->getMessage());


        }
        return false;

    }

    public function createCompanyContact(\MePlus\Models\Company $company){


         try {
            $this->log->addInfo("Creating Company Contact...", [$company->name]);

            $contact = new \XeroPHP\Models\Accounting\Contact();
            $contact->setName($company->name);
            $contact->setEmailAddress($company->email);
           /* $contact->setFirstName($account->first_name);
            $contact->setLastName($account->last_name);*/
            $response = $this->xero->save($contact);
            $response = $response->getElements()[0];

            $contact->setGUID($response['ContactID']);

            $this->log->addInfo("Creating Company Contact...");


            return $contact;

         }catch(\XeroPHP\Remote\Exception\BadRequestException $e){

             $this->log->addInfo("Error creating Company Contact...", [$e->getMessage()]);

             throw new Exception("Error Creating Company Contact....".$e->getMessage());


         }
        return false;

    }

    /**
     * @return null
     * @throws \XeroPHP\Exception
     */
    public function saveInvoice(){

        $this->log->addInfo("Saving Invoice". serialize($this->invoice));

        $date = new \DateTime(date('Y-m-d', strtotime('+30 days')));
        $this->invoice->setDueDate($date);

        $result = $this->xero->save($this->invoice);

        return $result;
    }


    private function validateItem(\MePlus\Models\BundleItems $item){

        try {

            //check if item exist in the invetory
            return  $this->xero->loadByGUID('Accounting\Item', $item->xero_code);


        }catch(\XeroPHP\Remote\Exception\NotFoundException $e){



        }
        return false;
    }

    /**
     * @param \MePlus\Models\BundleItems $item
     */
    private function getUnitAmount(\MePlus\Models\Invoices $invoice, \MePlus\Models\Accounts $account){

        $item = $invoice->item;
        $unit_cost  = $invoice->price;

        $this->log->addInfo("Current Unit Cost $  $unit_cost ");

        if( $this->invoice->getType()== \XeroPHP\Models\Accounting\Invoice::INVOICE_TYPE_ACCPAY){

            $commission =  $unit_cost * $account->xero->xero_percentage/10000;

            $unit_cost =   ($unit_cost- $commission );

            $this->log->addInfo("Comission $ $commission ");

        }

        $this->log->addInfo("Adding Unit Cost $  $unit_cost ");
        return $unit_cost;
    }

    /**
     * @param \MePlus\Models\Accounts $plan_manager
     * @param $type
     * @return mixed
     */
    private function validateContact($contact, $type){


        if($type == \XeroPHP\Models\Accounting\Invoice::INVOICE_TYPE_ACCREC && $contact->type !== \MePlus\Models\User::PM_MANAGER){

            $this->log->addInfo("User not a Plan Manager....", ($contact->toArray()));
            throw new \Exception("User not a Plan Manager");
        }

        if($type == \XeroPHP\Models\Accounting\Invoice::INVOICE_TYPE_ACCPAY && $contact->type !== \MePlus\Models\User::PROVIDER){

            $this->log->addInfo("User not a Provider....", ($contact->toArray()));
            throw new \Exception("User is not a Provider");
        }

        return $contact;


    }


    public function log($message, array $data=[]){

        $this->log->addInfo($message,$data);
    }

}