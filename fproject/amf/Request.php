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

namespace fproject\amf;

use fproject\amf\value\messaging\AbstractMessage;
use fproject\amf\value\MessageHeader;
use fproject\amf\value\MessageBody;
use fproject\amf\parse\InputStream;
use fproject\amf\parse\Amf0Deserializer;
use Exception;

/**
 * Handle the incoming AMF request by deserializing the data to php object
 * types and storing the data for fproject\amf\Server to handle for processing.
 *
 * @todo       Currently not checking if the object needs to be Type Mapped to a server object.
 */
class Request
{
    /**
     * @var int AMF client type (AMF0, AMF3)
     */
    protected $_clientType = 0; // default AMF0

    /**
     * @var array Message bodies
     */
    protected $_bodies = [];

    /**
     * @var array Message headers
     */
    protected $_headers = [];

    /**
     * @var int Message encoding to use for objects in response
     */
    protected $_objectEncoding = 0;

    /**
     * @var InputStream
     */
    protected $_inputStream;

    /**
     * @var Amf0Deserializer
     */
    protected $_deserializer;

    /**
     * Time of the request
     * @var  mixed
     */
    protected $_time;

    /**
     * Prepare the AMF InputStream for parsing.
     *
     * @param  string $request
     * @return Request
     */
    public function initialize($request)
    {
        $this->_inputStream  = new InputStream($request);
        $this->_deserializer = new Amf0Deserializer($this->_inputStream);
        $this->readMessage($this->_inputStream);
        return $this;
    }

    /**
     * Takes the raw AMF input stream and converts it into valid PHP objects
     *
     * @param InputStream $stream
     * @return Request
     * @throws AmfException
     */
    public function readMessage(InputStream $stream)
    {
        $clientVersion = $stream->readUnsignedShort();
        if (($clientVersion != Constants::AMF0_OBJECT_ENCODING)
            && ($clientVersion != Constants::AMF3_OBJECT_ENCODING)
            && ($clientVersion != Constants::FMS_OBJECT_ENCODING))
        {
            throw new AmfException('Unknown Player Version ' . $clientVersion);
        }

        $this->_bodies  = [];
        $this->_headers = [];
        $headerCount    = $stream->readInt();

        // Iterate through the AMF envelope header
        while ($headerCount--) {
            $this->_headers[] = $this->readHeader();
        }

        // Iterate through the AMF envelope body
        $bodyCount = $stream->readInt();
        while ($bodyCount--) {
            $this->_bodies[] = $this->readBody();
        }

        return $this;
    }

    /**
     * Deserialize a message header from the input stream.
     *
     * A message header is structured as:
     * - NAME String
     * - MUST UNDERSTAND Boolean
     * - LENGTH Int
     * - DATA Object
     * @return MessageHeader
     * @throws AmfException
     */
    public function readHeader()
    {
        $name     = $this->_inputStream->readUTF();
        $mustRead = (bool)$this->_inputStream->readByte();
        $length   = $this->_inputStream->readLong();

        try {
            $data = $this->_deserializer->readTypeMarker();
        } catch (Exception $e) {
            throw new AmfException('Unable to parse ' . $name . ' header data: ' . $e->getMessage() . ' '. $e->getLine(), 0, $e);
        }

        $header = new MessageHeader($name, $mustRead, $data, $length);
        return $header;
    }

    /**
     * Deserialize a message body from the input stream
     * @return MessageBody
     * @throws AmfException
     */
    public function readBody()
    {
        $targetURI   = $this->_inputStream->readUTF();
        $responseURI = $this->_inputStream->readUTF();
        /*$length  = */$this->_inputStream->readLong();

        try {
            $data = $this->_deserializer->readTypeMarker();
        } catch (Exception $e) {
            throw new AmfException('Unable to parse ' . $targetURI . ' body data ' . $e->getMessage(), 0, $e);
        }

        // Check for AMF3 objectEncoding
        if ($this->_deserializer->getObjectEncoding() == Constants::AMF3_OBJECT_ENCODING) {
            /*
             * When and AMF3 message is sent to the server it is nested inside
             * an AMF0 array called Content. The following code gets the object
             * out of the content array and sets it as the message data.
             */
            if(is_array($data) && $data[0] instanceof AbstractMessage){
                $data = $data[0];
            }

            // set the encoding so we return our message in AMF3
            $this->_objectEncoding = Constants::AMF3_OBJECT_ENCODING;
        }

        $body = new MessageBody($targetURI, $responseURI, $data);
        return $body;
    }

    /**
     * Return an array of the body objects that were found in the amf request.
     *
     * @return MessageBody[] {target, response, length, content}
     */
    public function getAmfBodies()
    {
        return $this->_bodies;
    }

    /**
     * Accessor to private array of message bodies.
     *
     * @param  MessageBody $message
     * @return Request
     */
    public function addAmfBody(MessageBody $message)
    {
        $this->_bodies[] = $message;
        return $this;
    }

    /**
     * Return an array of headers that were found in the amf request.
     *
     * @return array {operation, mustUnderstand, length, param}
     */
    public function getAmfHeaders()
    {
        return $this->_headers;
    }

    /**
     * Return the either 0 or 3 for respect AMF version
     *
     * @return int
     */
    public function getObjectEncoding()
    {
        return $this->_objectEncoding;
    }

    /**
     * Set the object response encoding
     *
     * @param  mixed $int
     * @return Request
     */
    public function setObjectEncoding($int)
    {
        $this->_objectEncoding = $int;
        return $this;
    }
}
