<?php namespace Elijan\LaravelXero;

use Illuminate\Support\ServiceProvider;

class LaravelXeroServiceProvider extends ServiceProvider {

	/**
	 * Indicates if loading of the provider is deferred.
	 *
	 * @var bool
	 */
	protected $defer = false;

	public function boot()
	{
		$this->package('elijan/laravel-xero');

	}

	/**
	 * Register the service provider.
	 *
	 * @return void
	 */
	public function register()
	{
		//

		$this->app['laravel-xero'] = $this->app->share(function($app)
		{

			return new LaravelXero($app['config']);
		});
	}

	/**
	 * Get the services provided by the provider.
	 *
	 * @return array
	 */
	public function provides()
	{
		return array('laravel-xero');
	}

}
