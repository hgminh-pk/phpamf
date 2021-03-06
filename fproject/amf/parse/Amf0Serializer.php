<?php
///////////////////////////////////////////////////////////////////////////////
//
// © Copyright f-project.net 2010-present.
//
// Licensed under the Apache License, Version 2.0 (the "License");
// you may not use this file except in compliance with the License.
// You may obtain a copy of the License at
//
//     http://www.apache.org/licenses/LICENSE-2.0
//
// Unless required by applicable law or agreed to in writing, software
// distributed under the License is distributed on an "AS IS" BASIS,
// WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
// See the License for the specific language governing permissions and
// limitations under the License.
//
///////////////////////////////////////////////////////////////////////////////

namespace fproject\amf\parse;

use fproject\amf\Constants;
use DateTime;
use fproject\amf\AmfException;

/**
 * Serializer PHP misc types back to there corresponding AMF0 Type Marker.
 *
 */
class Amf0Serializer extends Serializer
{
    /**
     * @var string Name of the class to be returned
     */
    protected $_className = '';

    /**
     * An array of reference objects
     * @var array
     */
    protected $_referenceObjects = [];

    /**
     * Determine type and serialize accordingly
     *
     * Checks to see if the type was declared and then either
     * auto negotiates the type or relies on the user defined markerType to
     * serialize the data into amf
     *
     * @param  mixed $data
     * @param  mixed $markerType
     * @param  mixed $dataByVal
     * @return Amf0Serializer
     * @throws AmfException for unrecognized types or data
     */
    public function writeTypeMarker(&$data, $markerType = null, $dataByVal = false)
    {
        // Workaround for PHP5 with E_STRICT enabled complaining about "Only
        // variables should be passed by reference"
        if ((null === $data) && ($dataByVal !== false)) {
            $data = &$dataByVal;
        }
        if (null !== $markerType) {
            //try to reference the given object
            if (!$this->writeObjectReference($data, $markerType)) {
                // Write the Type Marker to denote the following action script data type
                $this->_stream->writeByte($markerType);
                switch($markerType) {
                    case Constants::AMF0_NUMBER:
                        $this->_stream->writeDouble($data);
                        break;
                    case Constants::AMF0_BOOLEAN:
                        $this->_stream->writeByte($data);
                        break;
                    case Constants::AMF0_STRING:
                        $this->_stream->writeUTF($data);
                        break;
                    case Constants::AMF0_OBJECT:
                        $this->writeObject($data);
                        break;
                    case Constants::AMF0_NULL:
                        break;
                    case Constants::AMF0_REFERENCE:
                        $this->_stream->writeInt($data);
                        break;
                    case Constants::AMF0_MIXEDARRAY:
                        // Write length of numeric keys as zero.
                        $this->_stream->writeLong(0);
                        $this->writeObject($data);
                        break;
                    case Constants::AMF0_ARRAY:
                        $this->writeArray($data);
                        break;
                    case Constants::AMF0_DATE:
                        $this->writeDate($data);
                        break;
                    case Constants::AMF0_LONGSTRING:
                        $this->_stream->writeLongUTF($data);
                        break;
                    case Constants::AMF0_TYPEDOBJECT:
                        $this->writeTypedObject($data);
                        break;
                    case Constants::AMF0_AMF3:
                        $this->writeAmf3TypeMarker($data);
                        break;
                    default:
                        throw new AmfException("Unknown Type Marker: " . $markerType);
                }
            }
        } else {
            if (is_resource($data)) {
                $data = TypeLoader::handleResource($data);
            }
            switch (true) {
                case (is_int($data) || is_float($data)):
                    $markerType = Constants::AMF0_NUMBER;
                    break;
                case (is_bool($data)):
                    $markerType = Constants::AMF0_BOOLEAN;
                    break;
                case (is_string($data) && (($this->_mbStringFunctionsOverloaded ? mb_strlen($data, '8bit') : strlen($data)) > 65536)):
                    $markerType = Constants::AMF0_LONGSTRING;
                    break;
                case (is_string($data)):
                    $markerType = Constants::AMF0_STRING;
                    break;
                case (is_object($data)):
                    //20140627 NguyenBS Remove Zend_Date
                    if (($data instanceof DateTime)) {
                        $markerType = Constants::AMF0_DATE;
                    } else {

                        if($className = $this->getClassName($data)){
                            //Object is a Typed object set classname
                            $markerType = Constants::AMF0_TYPEDOBJECT;
                            $this->_className = $className;
                        } else {
                            // Object is a generic classname
                            $markerType = Constants::AMF0_OBJECT;
                        }
                        break;
                    }
                    break;
                case (null === $data):
                    $markerType = Constants::AMF0_NULL;
                    break;
                case (is_array($data)):
                    // check if it is an associative array
                    $i = 0;
                    foreach (array_keys($data) as $key) {
                        // check if it contains non-integer keys
                        if (!is_numeric($key) || intval($key) != $key) {
                            $markerType = Constants::AMF0_OBJECT;
                            break;
                            // check if it is a sparse indexed array
                         } else if ($key != $i) {
                             $markerType = Constants::AMF0_MIXEDARRAY;
                             break;
                         }
                         $i++;
                    }
                    // Dealing with a standard numeric array
                    if(!$markerType){
                        $markerType = Constants::AMF0_ARRAY;
                        break;
                    }
                    break;
                default:
                    throw new AmfException('Unsupported data type: ' . gettype($data));
            }

            $this->writeTypeMarker($data, $markerType);
        }
        return $this;
    }

    /**
     * Check if the given object is in the reference table, write the reference if it exists,
     * otherwise add the object to the reference table
     *
     * @param mixed  $object object reference to check for reference
     * @param string $markerType AMF type of the object to write
     * @param mixed  $objectByVal object to check for reference
     * @return Boolean true, if the reference was written, false otherwise
     */
    protected function writeObjectReference(&$object, $markerType, $objectByVal = false)
    {
        // Workaround for PHP5 with E_STRICT enabled complaining about "Only
        // variables should be passed by reference"
        if ((null === $object) && ($objectByVal !== false)) {
            $object = &$objectByVal;
        }

        if ($markerType == Constants::AMF0_OBJECT
            || $markerType == Constants::AMF0_MIXEDARRAY
            || $markerType == Constants::AMF0_ARRAY
            || $markerType == Constants::AMF0_TYPEDOBJECT
        ) {
            $ref = array_search($object, $this->_referenceObjects, true);
            //handle object reference
            if($ref !== false){
                $this->writeTypeMarker($ref,Constants::AMF0_REFERENCE);
                return true;
            }

            $this->_referenceObjects[] = $object;
        }

        return false;
    }

    /**
     * Write a PHP array with string or mixed keys.
     *
     * @param $object
     * @return Amf0Serializer
     * @throws AmfException
     */
    public function writeObject($object)
    {
        // Loop each element and write the name of the property.
        foreach ($object as $key => &$value) {
            // skip variables starting with an _ private transient
            if( $key[0] == "_") continue;
            $this->_stream->writeUTF($key);
            $this->writeTypeMarker($value);
        }

        // Write the end object flag
        $this->_stream->writeInt(0);
        $this->_stream->writeByte(Constants::AMF0_OBJECTTERM);
        return $this;
    }

    /**
     * Write a standard numeric array to the output stream. If a mixed array
     * is encountered call writeTypeMarker with mixed array.
     *
     * @param array $array
     * @return Amf0Serializer
     */
    public function writeArray(&$array)
    {
        $length = count($array);
        if (!$length < 0) {
            // write the length of the array
            $this->_stream->writeLong(0);
        } else {
            // Write the length of the numeric array
            $this->_stream->writeLong($length);
            for ($i=0; $i<$length; $i++) {
                $value = isset($array[$i]) ? $array[$i] : null;
                $this->writeTypeMarker($value);
            }
        }
        return $this;
    }

    //20140627 NguyenBS remove Zend_Date
    /**
     * Convert the DateTime into an AMF Date
     *
     * @param  DateTime $data
     * @throws AmfException
     * @return Amf0Serializer
     */
    public function writeDate($data)
    {
        if ($data instanceof DateTime) {
            $dateString = $data->format('U');
        } else {
            throw new AmfException('Invalid date specified; must be a DateTime object');
        }
        $dateString *= 1000;

        // Make the conversion and remove milliseconds.
        $this->_stream->writeDouble($dateString);

        // Flash does not respect timezone but requires it.
        $this->_stream->writeInt(0);

        return $this;
    }

    /**
     * Write a class mapped object to the output stream.
     *
     * @param  object $data
     * @return Amf0Serializer
     */
    public function writeTypedObject($data)
    {
        $this->_stream->writeUTF($this->_className);
        $this->writeObject($data);
        return $this;
    }

    /**
     * Encountered and AMF3 Type Marker use AMF3 serializer. Once AMF3 is
     * encountered it will not return to AMf0.
     *
     * @param  string $data
     * @return Amf0Serializer
     */
    public function writeAmf3TypeMarker(&$data)
    {
        $serializer = new Amf3Serializer($this->_stream);
        $serializer->writeTypeMarker($data);
        return $this;
    }

    /**
     * Find if the class name is a class mapped name and return the
     * respective classname if it is.
     *
     * @param object $object
     * @return false|string $className
     */
    protected function getClassName($object)
    {
        //Check to see if the object is a typed object and we need to change
        //$className = '';
        switch (true) {
            // the return class mapped name back to actionscript class name.
            case TypeLoader::getMappedClassName(get_class($object)):
                $className = TypeLoader::getMappedClassName(get_class($object));
                break;
                // Check to see if the user has defined an explicit Action Script type.
            case isset($object->_explicitType):
                $className = $object->_explicitType;
                break;
                // Check if user has defined a method for accessing the Action Script type
            case method_exists($object, 'getASClassName'):
                $className = $object->getASClassName();
                break;
                // No return class name is set make it a generic object
            case ($object instanceof \stdClass):
                $className = '';
                break;
        // By default, use object's class name
            default:
        $className = get_class($object);
                break;
        }
        if(!$className == '') {
            return $className;
        } else {
            return false;
        }
    }
}
