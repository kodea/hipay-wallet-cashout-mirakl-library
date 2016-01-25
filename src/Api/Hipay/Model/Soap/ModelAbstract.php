<?php

namespace Hipay\MiraklConnector\Api\Hipay\Model\Soap;

use Hipay\MiraklConnector\Service\Validation\ModelValidator;

/**
 * Class SoapModelAbstract
 * Base class for the models used as a request or response of a soap call.
 *
 * @author    Ivanis Kouamé <ivanis.kouame@smile.fr>
 * @copyright 2015 Smile
 */
abstract class ModelAbstract
{
    /**
     * @return string
     */
    public function getSoapParameterKey()
    {
        return lcfirst(substr(strrchr(get_called_class(), '\\'), 1));
    }

    /**
     * Get SOAP parameter data.
     */
    public function getSoapParameterData()
    {
        return get_object_vars($this);
    }

    /**
     * Add the object data in the parameters array
     * Validate data before merging.
     *
     * @param array $parameters
     *
     * @return array
     */
    public function mergeIntoParameters(array $parameters = array())
    {
        $this->validate();

        return $parameters + $this->getSoapParameterData();
    }

    /**
     * Validate the model before sending it
     * Use ModelValidator.
     */
    public function validate()
    {
        ModelValidator::validate($this);
    }
}
