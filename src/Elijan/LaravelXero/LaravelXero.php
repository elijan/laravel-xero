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
        $this->log->pushHandler(new StreamHandler(storage_path('logs').'/xero-service'.date('y-m-d').'log', Logger::INFO));


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

        $this->log->addInfo("Create Invoice Receivable....");


       $contact = $this->validateContact($planManager, \XeroPHP\Models\Accounting\Invoice::INVOICE_TYPE_ACCREC);

        $contact = $this->xero->loadByGUID('Accounting\Contact',$planManager->xero->xero_guid);



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
    public function createInvoiceAccPay(\MePlus\Models\PlanManagerXeroCompanyAccount $company, $reference_id){

        $this->log->addInfo("Create Invoice Pay....");



        $contact = $this->xero->loadByGUID('Accounting\Contact',$company->xero_guid);


        $this->invoice  =   new \XeroPHP\Models\Accounting\Invoice();
        $this->invoice->setStatus(\XeroPHP\Models\Accounting\Invoice::INVOICE_STATUS_DRAFT);
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

    public  function addItemToInvoice(\MePlus\Models\InvoiceLineItems $item, $account_code){



        if( !$this->invoice instanceof \XeroPHP\Models\Accounting\Invoice){

            throw new \Exception('Invoice Not created');
        }




     /*   $xero_item = new \XeroPHP\Models\Accounting\Item();

        $xero_item->setCode($item->item_code);
        $xero_item->setName($item->item->item->name);
        $xero_item->setIsTrackedAsInventory(false);*/

       /* $sales = new \XeroPHP\Models\Accounting\Item\Sale();
        $sales->setUnitPrice($item->price);
        $sales->setAccountCode('200');

        $xero_item->addSalesDetail($sales);

        $response = $this->xero->save($xero_item);
        $response = $response->getElements()[0];*/

       /* $item->xero_guid = $response['ItemID'];
        $item->save();*/

        $this->log->addInfo("Add Invoice item....");

        $line_item =  new \XeroPHP\Models\Accounting\Invoice\LineItem();
        $line_item->setItemCode($item->item_code);
        $line_item->setDescription($item->item->item->name);
        $line_item->setAccountCode($account_code);
        $line_item->setQuantity($item->quantity);
        $line_item->setTaxType(\XeroPHP\Models\Accounting\ReportTaxType::AUSTRALIUM_BASEXCLUDED);
        $line_item->setUnitAmount($item->unformatted_price);

        $this->invoice->addLineItem($line_item);

        $this->log->addInfo("added....");


    }

    /**
     * @param \MePlus\Models\Accounts $account
     *
     */
    public function createContact(\MePlus\Models\Company $company){


         try {
            $this->log->addInfo("Creating Contact...", [$company->name]);

            $contact = new \XeroPHP\Models\Accounting\Contact();
            $contact->setName($company->name);
            $contact->setEmailAddress($company->email);
           /* $contact->setFirstName($account->first_name);
            $contact->setLastName($account->last_name);*/
            $response = $this->xero->save($contact);
            $response = $response->getElements()[0];

            $contact->setGUID($response['ContactID']);

            $this->log->addInfo("Creating Contact...");


            return $contact;

         }catch(\XeroPHP\Remote\Exception\BadRequestException $e){

             $this->log->addInfo("Error creating account...", [$e->getMessage()]);



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