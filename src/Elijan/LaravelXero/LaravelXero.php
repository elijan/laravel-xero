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
        $this->log =  new Logger('paypal-adaptive');
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

        if(\Auth::account()->xero_guid==null){

            return ['error'=>true, 'message'=>" You don't have NDIA account setup. Please click <a href='". route('settings.index')."'> Edit Account </a> to add NDIA settings"];

        }

        if(null === $oauth_session = $this->getOAuthSession() ){

            $url = new URL($this->xero, URL::OAUTH_REQUEST_TOKEN);
            $request = new Request($this->xero, $url);

            //Here's where you'll see if your keys are valid.  You can catch a BadRequestException.
            try {
                $request->send();
            } catch (\Exception $e){
                print_r($e);
                if ($request->getResponse()) {
                    print_r($request->getResponse()->getOAuthResponse());
                }
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

            return ['error'=>true, 'message'=>"<h3> You are already connected to Xero APi </h3>"];

            exit;
        }



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
        $this->invoice->setInvoiceNumber($reference_id);
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

        $this->log->addInfo("Current Unit Cost $  $unit_cost ");

        if( $this->invoice->getType()== \XeroPHP\Models\Accounting\Invoice::INVOICE_TYPE_ACCPAY){

            $commission =  $unit_cost * $invoice->provider->xero_percentage/10000;

            $unit_cost =   ($unit_cost- $commission );

            $this->log->addInfo("Comission $ $commission ");

        }

        $this->log->addInfo("Adding Unit Cost $  $unit_cost ");
        echo $unit_cost;
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