<?php

class Message {
    public $ProtocolID;
    public $Index;
    public $Enum = [];
    public $EnumDefault = [];
    public $Variable = [];
    public $Unit;
    public $Arithmatic = [];
    public $Condition = [];
    public $Range = [];
    public $Structure = false;
    public $VariableAssign = [];

    public function __construct($ProtocolID, $Index) {
        $this->ProtocolID = $ProtocolID;
        $this->Index = $Index;
    }

    function toSignedInt($value, $bits) {
        $mask = (1 << $bits) - 1;
        $value = $value & $mask;
        $signBit = 1 << ($bits - 1);
        if ($value & $signBit) {
            return $value - (1 << $bits);
        }
        return $value;
    }    
    
    public function encodeMessage($value) {
        // Enum: String → Integer (Reverse-Lookup)
        if (!empty($this->Enum) && !is_numeric($value)) {
            foreach ($this->Enum as $item) {
                if ((string)$item['String'] === (string)$value) {
                    return (int)$item['Value'];
                }
            }
            return 0;
        }

        // Arithmetic: Multiplikation (Umkehrung der Division)
        if (!empty($this->Arithmatic) && $this->Arithmatic['Operation'] === 'Division') {
            $divisor = (float)$this->Arithmatic['Value'];
            if ($divisor != 0) {
                return (int)round((float)$value * $divisor);
            }
        }

        return (int)$value;
    }

    public function decodeMessage($value) {
        // 1. Signed-Konvertierung: Bit-Breite aus MessageNumber-Typ (Bits 9-10)
        if (!empty($this->Variable) && !empty($this->Variable['Signed']) && $this->Variable['Signed'] != 'false') {
            $typeCode = (hexdec($this->Index) & 0x600) >> 9;
            if ($typeCode === 2) {
                $value = $this->toSignedInt($value, 32); // LongVariable
            } elseif ($typeCode === 1) {
                $value = $this->toSignedInt($value, 16); // Variable
            } else {
                $value = $this->toSignedInt($value, 8);  // Enum
            }
        }

        // 2. Arithmetic
        if (!empty($this->Arithmatic)) {
            $operation = $this->Arithmatic['Operation'];
            $operand = $this->Arithmatic['Value'];
            if ($operation === 'Division' && is_numeric($operand) && $operand != 0) {
                $value = $value / $operand;
            }
            // Add other operations here if needed
        }
        
        // 3. Enum lookup
        if (!empty($this->Enum)) {
            $foundValue = false;
            
            foreach ($this->Enum as $enumItem) {
                if ((string)$enumItem['Value'] === (string)$value) {
                    $value = $enumItem['String'];
                    $foundValue = true;
                    //return $enumItem['String'];
                    break;
                }
            }

            if (!$foundValue && !empty($this->EnumDefault)) {
                foreach ($this->EnumDefault as $def) {
                    if ($def['Tag'] === 'String') {
                        $value = $def['Value'];
                        break;
                    }
                }
            }
        }


        $unit = (!empty ($this->Unit)) ? $this->Unit : '';
                      
        // 5. Default: return (transformed) value
        return [$unit, $this->ProtocolID, $value];
    }
}

class nasaDecodeProtocol {
    private string $xmlFile;

    public array $variables;
    public array $messages;
    public array $unprocessedTags;
    
    public function __construct(string $xmlFile) {
        $this->xmlFile = $xmlFile;
        $this->variables = [];
        $this->messages = [];
        $this->unprocessedTags = [];
    }

    public function getVariables () : array {
        return $this->variables;
    }
    
    public function getMessages () : array {
        return $this->messages;
    }
    
    public function getUnprocessedTags () {
        return $this->unprocessedTags;
    }

    public function getItemObject (int $itemId) : ?Message {
        if (isset ($this->messages[$itemId])) {
            return $this->messages[$itemId];
        }
        return null;
    }
    
    function parseNasaProtocolXML() {
        
        $xml = simplexml_load_file($this->xmlFile, 'SimpleXMLElement', 0, 'urn:AirKitRuleSchema');
        $xml->registerXPathNamespace('ns', 'urn:AirKitRuleSchema');

        $handledTags = [
            'Enum', 'Variable', 'Unit', 'Arithmatic', 'Condition', 'Range', 'Message', 'VariableList', 'Variable',
            'LogicalOperation', 'Operand', 'Action', 'Structure', 'VariableAssign', 'Item', 'Default', 'String'
        ];

        // Helper to collect all tag names recursively
        function collectTags($element, &$foundTags) {
            foreach ($element->children() as $child) {
                $foundTags[(string)$child->getName()] = true;
                collectTags($child, $foundTags);
            }
        }
            
            // Extract VariableList (still as array, for simplicity)
            $variables = [];
        foreach ($xml->xpath('//ns:VariableList/ns:Variable') as $var) {
            $variables[] = [
                'Identifier' => (string)$var['Identifier'],
                'Type' => (string)$var['Type'],
                'IsGlobal' => (string)$var['IsGlobal'],
                'Value' => (string)$var
            ];
        }

        // Extract Messages as objects and collect tags
        $messages = [];
        $allTags = [];
        foreach ($xml->xpath('//ns:Message') as $msg) {
            $ProtocolID = (string)$msg['ProtocolID'];
            $Index = (string)$msg['Index'];
            $msgObj = new Message($ProtocolID, $Index);

            collectTags($msg, $allTags);

            // Enum Items & Default
            if (isset($msg->Enum)) {
                foreach ($msg->Enum->Item as $item) {
                    $msgObj->Enum[] = [
                        'Value' => (string)$item['Value'],
                        'String' => (string)$item->String
                    ];
                }
                // Handle <Default>
                if (isset($msg->Enum->Default)) {
                    foreach ($msg->Enum->Default->children() as $defChild) {
                        $msgObj->EnumDefault[] = [
                            'Tag' => $defChild->getName(),
                            'Value' => (string)$defChild
                        ];
                    }
                }
            }

            // Variable (attributes)
            if (isset($msg->Variable)) {
                foreach ($msg->Variable->attributes() as $k => $v) {
                    $msgObj->Variable[(string)$k] = (string)$v;
                }
            }

            // Unit
            if (isset($msg->Unit)) {
                $msgObj->Unit = (string)$msg->Unit;
            }

            // Arithmatic
            if (isset($msg->Arithmatic)) {
                $msgObj->Arithmatic = [
                    'Operation' => (string)$msg->Arithmatic['Operation'],
                    'Value' => (string)$msg->Arithmatic
                ];
            }

            // Condition (if present)
            if (isset($msg->Condition)) {
                foreach ($msg->Condition->children() as $condChild) {
                    if ($condChild->getName() === 'LogicalOperation') {
                        $condArr = [
                            'Operator' => (string)$condChild['Operator'],
                            'Operands' => []
                        ];
                        foreach ($condChild->Operand as $op) {
                            $condArr['Operands'][] = [
                                'Type' => (string)$op['Type'],
                                'Value' => (string)$op
                            ];
                        }
                        $msgObj->Condition[] = $condArr;
                    } elseif ($condChild->getName() === 'Action') {
                        $msgObj->Condition[] = [
                            'KindOfAction' => (string)$condChild['KindOfAction'],
                            'Value' => (string)$condChild
                        ];
                    }
                }
            }

            // Range (if present)
            if (isset($msg->Range)) {
                $msgObj->Range = [
                    'Min' => (string)$msg->Range['Min'],
                    'Max' => (string)$msg->Range['Max']
                ];
            }

            // Structure
            if (isset($msg->Structure)) {
                $msgObj->Structure = true;
            }

            // VariableAssign
            if (isset($msg->VariableAssign)) {
                foreach ($msg->VariableAssign as $va) {
                    $msgObj->VariableAssign[] = [
                        'Identifier' => (string)$va['Identifier']
                    ];
                }
            }

            // Store in lookup array by Index
            $Index = hexdec($Index); // store index as decimal value
            $messages[$Index] = $msgObj;
        }

        // Compute unprocessed tags
        $allTags = array_keys($allTags);
        $unprocessedTags = array_diff($allTags, $handledTags);

        $this->variables = $variables;
        $this->messages  = $messages;
        $this->unprocessedTags = array_values($unprocessedTags);

        return true;
        /*
        return [
            'VariableList' => $variables,
            'Messages' => $messages, // This is now an array of Message objects, keyed by Index
            'UnprocessedTags' => array_values($unprocessedTags)
        ];
        */
    }
    
}

/*
// Example for testing class
$nasaDecodedObject = new nasaDecodeProtocol ('./protocoldescription/NASA.ptc');

$result = $nasaDecodedObject->parseNasaProtocolXML();

if ($result) {
    //print_r($nasaDecodedObject->getVariables());
    //print_r($nasaDecodedObject->getMessages());
    print_r($nasaDecodedObject->getUnprocessedTags());

    $obj = $nasaDecodedObject->getItemObject (0x8003);
    if ($obj != null) {
        //var_dump  ($obj);

        list ($unit,$HRName, $decodedValue) = $obj->decodeMessage (1);
        echo "Result: $HRName: $decodedValue $unit";
    } else {
        echo "obj xy does not exist!";
    }
    
}
*/

?>
