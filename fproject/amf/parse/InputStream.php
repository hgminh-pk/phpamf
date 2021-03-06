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

use fproject\common\utils\BinaryStream;

/**
 * InputStream is used to iterate at a binary level through the AMF request.
 *
 * InputStream extends BinaryStream as eventually BinaryStream could be placed
 * outside of PHP AMF in order to allow other packages to use the class.
 *
 */
class InputStream extends BinaryStream
{
}
