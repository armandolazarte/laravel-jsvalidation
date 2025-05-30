<?php

namespace Proengsoft\JsValidation;

use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Foundation\Http\FormRequest as IlluminateFormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\Validator;
use Proengsoft\JsValidation\Javascript\JavascriptValidator;
use Proengsoft\JsValidation\Javascript\MessageParser;
use Proengsoft\JsValidation\Javascript\RuleParser;
use Proengsoft\JsValidation\Javascript\ValidatorHandler;
use Proengsoft\JsValidation\Remote\FormRequest;
use Proengsoft\JsValidation\Support\DelegatedValidator;
use Proengsoft\JsValidation\Support\ValidationRuleParserProxy;

class JsValidatorFactory
{
    const ASTERISK = '__asterisk__';

    /**
     * The application instance.
     *
     * @var \Illuminate\Container\Container
     */
    protected $app;

    /**
     * Configuration options.
     *
     * @var array
     */
    protected $options;

    /**
     * Create a new Validator factory instance.
     *
     * @param  \Illuminate\Container\Container  $app
     * @param  array  $options
     */
    public function __construct($app, array $options = [])
    {
        $this->app = $app;
        $this->setOptions($options);
    }

    /**
     * @param  $options
     * @return void
     */
    protected function setOptions($options)
    {
        $options['disable_remote_validation'] = empty($options['disable_remote_validation']) ? false : $options['disable_remote_validation'];
        $options['view'] = empty($options['view']) ? 'jsvalidation:bootstrap' : $options['view'];
        $options['form_selector'] = empty($options['form_selector']) ? 'form' : $options['form_selector'];

        $this->options = $options;
    }

    /**
     * Creates JsValidator instance based on rules and message arrays.
     *
     * @param  array  $rules
     * @param  array  $messages
     * @param  array  $customAttributes
     * @param  null|string  $selector
     * @return \Proengsoft\JsValidation\Javascript\JavascriptValidator
     */
    public function make(array $rules, array $messages = [], array $customAttributes = [], $selector = null)
    {
        $validator = $this->getValidatorInstance($rules, $messages, $customAttributes);

        return $this->validator($validator, $selector);
    }

    /**
     * Get the validator instance for the request.
     *
     * @param  array  $rules
     * @param  array  $messages
     * @param  array  $customAttributes
     * @return \Illuminate\Validation\Validator
     */
    protected function getValidatorInstance(array $rules, array $messages = [], array $customAttributes = [])
    {
        $factory = $this->app->make(ValidationFactory::class);

        $data = $this->getValidationData($rules, $customAttributes);
        $validator = $factory->make($data, $rules, $messages, $customAttributes);
        $validator->addCustomAttributes($customAttributes);

        return $validator;
    }

    /**
     * Gets fake data when validator has wildcard rules.
     *
     * @param  array  $rules
     * @param  array  $customAttributes
     * @return array
     */
    protected function getValidationData(array $rules, array $customAttributes = [])
    {
        $attributes = array_filter(array_keys($rules), function ($attribute) {
            return $attribute !== '' && mb_strpos($attribute, '*') !== false;
        });

        $attributes = array_merge(array_keys($customAttributes), $attributes);
        $data = array_reduce($attributes, function ($data, $attribute) {
            // Prevent wildcard rule being removed as an implicit attribute (not present in the data).
            $attribute = str_replace('*', self::ASTERISK, $attribute);

            Arr::set($data, $attribute, true);

            return $data;
        }, []);

        return $data;
    }

    /**
     * Creates JsValidator instance based on FormRequest.
     *
     * @param  $formRequest
     * @param  null|string  $selector
     * @return \Proengsoft\JsValidation\Javascript\JavascriptValidator
     *
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     */
    public function formRequest($formRequest, $selector = null)
    {
        if (! is_object($formRequest)) {
            $formRequest = $this->createFormRequest($formRequest);
        }

        if ($formRequest instanceof FormRequest) {
            return $this->newFormRequestValidator($formRequest, $selector);
        }

        return $this->oldFormRequestValidator($formRequest, $selector);
    }

    /**
     * Create form request validator.
     *
     * @param  FormRequest  $formRequest
     * @param  string  $selector
     * @return JavascriptValidator
     */
    private function newFormRequestValidator($formRequest, $selector)
    {
        // Replace all rules with Noop rules which are checked client-side and always valid to true.
        // This is important because jquery-validation expects fields under validation to have rules present. For
        // example, if you mark a field as invalid without a defined rule, then unhighlight won't be called.
        $rules = method_exists($formRequest, 'rules') ? $this->app->call([$formRequest, 'rules']) : [];
        foreach ($rules as $key => $value) {
            $rules[$key] = 'proengsoft_noop';
        }

        // This rule controls AJAX validation of all fields.
        $rules['proengsoft_jsvalidation'] = RuleParser::FORM_REQUEST_RULE_NAME;

        $baseValidator = $this->getValidatorInstance($rules);

        return $this->validator($baseValidator, $selector);
    }

    /**
     * Create a form request validator instance.
     *
     * @param  IlluminateFormRequest  $formRequest
     * @param  string  $selector
     * @return JavascriptValidator
     */
    private function oldFormRequestValidator($formRequest, $selector)
    {
        $rules = method_exists($formRequest, 'rules') ? $this->app->call([$formRequest, 'rules']) : [];

        $validator = $this->getValidatorInstance($rules, $formRequest->messages(), $formRequest->attributes());

        $jsValidator = $this->validator($validator, $selector);

        if (method_exists($formRequest, 'withJsValidator')) {
            $formRequest->withJsValidator($jsValidator);
        }

        return $jsValidator;
    }

    /**
     * @param  string|array  $class
     * @return array
     */
    protected function parseFormRequestName($class)
    {
        $params = [];
        if (is_array($class)) {
            $params = empty($class[1]) ? $params : $class[1];
            $class = $class[0];
        }

        return [$class, $params];
    }

    /**
     * Creates and initializes an Form Request instance.
     *
     * @param  string  $class
     * @return IlluminateFormRequest
     *
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     */
    protected function createFormRequest($class)
    {
        /*
         * @var $formRequest \Illuminate\Foundation\Http\FormRequest
         * @var $request Request
         */
        [$class, $params] = $this->parseFormRequestName($class);

        $request = $this->app->__get('request');
        $formRequest = $this->app->build($class, $params);

        if ($request->hasSession() && $session = $request->session()) {
            $formRequest->setLaravelSession($session);
        }
        $formRequest->setUserResolver($request->getUserResolver());
        $formRequest->setRouteResolver($request->getRouteResolver());
        $formRequest->setContainer($this->app);
        $formRequest->query = $request->query;

        return $formRequest;
    }

    /**
     * Creates JsValidator instance based on Validator.
     *
     * @param  \Illuminate\Validation\Validator  $validator
     * @param  null|string  $selector
     * @return \Proengsoft\JsValidation\Javascript\JavascriptValidator
     */
    public function validator(Validator $validator, $selector = null)
    {
        return $this->jsValidator($validator, $selector);
    }

    /**
     * Creates JsValidator instance based on Validator.
     *
     * @param  \Illuminate\Validation\Validator  $validator
     * @param  null|string  $selector
     * @return \Proengsoft\JsValidation\Javascript\JavascriptValidator
     */
    protected function jsValidator(Validator $validator, $selector = null)
    {
        $remote = ! $this->options['disable_remote_validation'];
        $view = $this->options['view'];
        $selector = is_null($selector) ? $this->options['form_selector'] : $selector;
        $ignore = $this->options['ignore'];

        $delegated = new DelegatedValidator($validator, new ValidationRuleParserProxy($validator->getData()));
        $rules = new RuleParser($delegated, $this->getSessionToken());
        $messages = new MessageParser($delegated, isset($this->options['escape']) ? $this->options['escape'] : false);

        $jsValidator = new ValidatorHandler($rules, $messages);

        return new JavascriptValidator($jsValidator, compact('view', 'selector', 'remote', 'ignore'));
    }

    /**
     * Get and encrypt token from session store.
     *
     * @return null|string
     */
    protected function getSessionToken()
    {
        $token = null;
        if ($session = $this->app->__get('session')) {
            $token = $session->token();
        }

        if ($encrypter = $this->app->__get('encrypter')) {
            $token = $encrypter->encrypt($token);
        }

        return $token;
    }
}
