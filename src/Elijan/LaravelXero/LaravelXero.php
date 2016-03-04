<?php namespace Elijan\LaravelXero;

use GuzzleHttp\Subscriber\Redirect;
use Illuminate\Config\Repository;
use Illuminate\Support\Facades\Session;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use \XeroPHP\Remote\URL;
use \XeroPHP\Remote\Request;
use \Illuminate\Support\Facades\Auth as Auth;

class LaravelXero {



    private $config;

    private $invoice;

    private $xero;
    private $log;

    public function __construct(Repository $config){
        $this->log =  new Logger('xero-log');
        $this->log->pushHandler(new StreamHandler(storage_path('logs').'/xero-service.log', Logger::INFO));


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
     */
    public function createInvoiceAccRec(\MePlus\Models\Accounts $account, $reference_id){

        $this->log->addInfo("Create Invoice Receivable....");

        $contact = $this->validateInvoice($account, \XeroPHP\Models\Accounting\Invoice::INVOICE_TYPE_ACCREC);
        if($contact == false){

            if(!$contact = $this->createAccount($account)) {

                return false;
            }

        }

        $this->invoice  =   new \XeroPHP\Models\Accounting\Invoice();
        $this->invoice->setStatus('AUTHORISED');
        $this->invoice->setType(\XeroPHP\Models\Accounting\Invoice::INVOICE_TYPE_ACCREC);
        $this->invoice->setContact($contact);
        $this->invoice->setReference($reference_id);
    }


    /**
     * @param \MePlus\Models\Accounts $plan_manager
     * @param $reference_id\
     */
    public function createInvoiceAccPay(\MePlus\Models\Accounts $account, $reference_id){

        $this->log->addInfo("Create Invoice Pay....");

        $contact = $this->validateInvoice($account, \XeroPHP\Models\Accounting\Invoice::INVOICE_TYPE_ACCPAY);

        if($contact == false){
            //create an account

            if(!$contact = $this->createAccount($account)) {


                return false;
            }

        }


        $this->invoice  =   new \XeroPHP\Models\Accounting\Invoice();
        $this->invoice->setStatus(\XeroPHP\Models\Accounting\Invoice::INVOICE_STATUS_AUTHORISED);
        $this->invoice->setType(\XeroPHP\Models\Accounting\Invoice::INVOICE_TYPE_ACCPAY);

        $this->invoice->setContact($contact);
        $this->invoice->setInvoiceNumber($reference_id);
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

    public  function addItemToInvoice(\MePlus\Models\Invoices $invoice){


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
            $sales->setUnitPrice($this->getUnitAmount($invoice, $invoice->plan_manager));
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

        $line_item->setUnitAmount($this->getUnitAmount($invoice, $invoice->plan_manager));

        $this->invoice->addLineItem($line_item);
        $date = new \DateTime(date('Y-m-d', strtotime('+30 days')));
        $this->invoice->setDueDate($date);

    }

    /**
     * @param \MePlus\Models\Accounts $account
     *
     */
    public function createAccount(\MePlus\Models\Accounts $account){


         try {
            $this->log->addInfo("Creating Account...", [$account->id]);

            $contact = new \XeroPHP\Models\Accounting\Contact();
            $contact->setName($account->business_name);
            $contact->setEmailAddress($account->user->email);
            $contact->setFirstName($account->first_name);
            $contact->setLastName($account->last_name);
            $response = $this->xero->save($contact);

            $this->log->addInfo("Creating Account...response", serialize($response));
            $response = $response->getElements()[0];



            $account->xero->xero_guid = $response['ContactID'];
            $account->xero->save();

            return $contact;

         }catch(\XeroPHP\Remote\Exception\BadRequestException $e){

             $this->log->addError("Error creating account...", [$e->getMessage()]);



         }
        return false;

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
    private function validateInvoice(\MePlus\Models\Accounts $account, $type){


        if($type == \XeroPHP\Models\Accounting\Invoice::INVOICE_TYPE_ACCREC && $account->user->type !== \MePlus\Models\User::PM_MANAGER){

            $this->log->addInfo("User not a Plan Manager....", ($account->toArray()));
            throw new \Exception("User not a Plan Manager");
        }

        if($type == \XeroPHP\Models\Accounting\Invoice::INVOICE_TYPE_ACCPAY && $account->user->type !== \MePlus\Models\User::PROVIDER){

            $this->log->addInfo("User not a Provider....", ($account->toArray()));
            throw new \Exception("User is not a Provider");
        }


        if($account->xero->xero_guid){

            $this->log->addInfo("try to get the account by GUID if any....", ($account->toArray()));
            try {
                $contact =  $this->xero->loadByGUID('Accounting\Contact', $account->xero->xero_guid);

            }catch(\XeroPHP\Remote\Exception\NotFoundException $e){

                $this->log->addInfo("Account Not found...",  [$account->full_name]);
                return false;
            }
        }

        return $contact;


    }


    public function log($message, array $data=[]){

        $this->log->addInfo($message,$data);
    }

}