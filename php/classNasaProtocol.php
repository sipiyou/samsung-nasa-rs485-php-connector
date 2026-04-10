<?php

class AddressClass {
    const Outdoor = 0x10;
    const HTU = 0x11;
    const Indoor = 0x20;
    const ERV = 0x30;
    const Diffuser = 0x35;
    const MCU = 0x38;
    const RMC = 0x40;
    const WiredRemote = 0x50;
    const PIM = 0x58;
    const SIM = 0x59;
    const Peak = 0x5A;
    const PowerDivider = 0x5B;
    const OnOffController = 0x60;
    const WiFiKit = 0x62;
    const CentralController = 0x65;
    const DMS = 0x6A;
    const JIGTester = 0x80;
    const BroadcastSelfLayer = 0xB0;
    const BroadcastControlLayer = 0xB1;
    const BroadcastSetLayer = 0xB2;
    const BroadcastControlAndSetLayer = 0xB3;
    const BroadcastModuleLayer = 0xB4;
    const BroadcastCSM = 0xB7;
    const BroadcastLocalLayer = 0xB8;
    const BroadcastCSML = 0xBF;
    const Undefined = 0xFF;
}

class PacketType {
    const StandBy = 0;
    const Normal = 1;
    const Gathering = 2;
    const Install = 3;
    const Download = 4;
}

class DataType {
    const Undefined = 0;
    const Read = 1;
    const Write = 2;
    const Request = 3;
    const Notification = 4;
    const Response = 5;
    const Ack = 6;
    const Nack = 7;
}

class MessageSetType {
    const Enum = 0;
    const Variable = 1;
    const LongVariable = 2;
    const Structure = 3;
}

class MessageNumber {
    const Undefiend = 0;
    const ENUM_in_operation_power = 0x4000;
    const ENUM_in_operation_automatic_cleaning = 0x4111;
    const ENUM_in_water_heater_power = 0x4065;
    const ENUM_in_operation_mode = 0x4001;
    const ENUM_in_water_heater_mode = 0x4066;
    const ENUM_in_fan_mode = 0x4006;
    const ENUM_in_fan_mode_real = 0x4007;
    const ENUM_in_alt_mode = 0x4060;
    const ENUM_in_louver_hl_swing = 0x4011;
    const ENUM_in_louver_lr_swing = 0x407e;
    const ENUM_in_state_humidity_percent = 0x4038;
    const VAR_in_temp_room_f = 0x4203;
    const VAR_in_temp_target_f = 0x4201;
    const VAR_in_temp_water_outlet_target_f = 0x4247;
    const VAR_in_temp_water_tank_f = 0x4237;
    const VAR_out_sensor_airout = 0x8204;
    const VAR_in_temp_water_heater_target_f = 0x4235;
    const VAR_in_temp_eva_in_f = 0x4205;
    const VAR_in_temp_eva_out_f = 0x4206;
    const VAR_out_error_code = 0x8235;
    const LVAR_OUT_CONTROL_WATTMETER_1W_1MIN_SUM = 0x8413;
    const LVAR_OUT_CONTROL_WATTMETER_ALL_UNIT_ACCUM = 0x8414;
    const VAR_OUT_SENSOR_CT1 = 0x8217;
    const LVAR_NM_OUT_SENSOR_VOLTAGE = 0x24fc;
}

class Address {
    public $klass;
    public $channel;
    public $address;
    public $size = 3;

    public function __construct($klass = AddressClass::Undefined, $channel = 0, $address = 0) {
        $this->klass = $klass;
        $this->channel = $channel;
        $this->address = $address;
    }

    public static function parse($str) {
        $parts = explode('.', $str);
        return new Address(
            hexdec($parts[0]),
            hexdec($parts[1]),
            hexdec($parts[2])
        );
    }

    public static function getMyAddress() {
        return new Address(AddressClass::JIGTester, 0xFF, 0);
    }

    public function decode(array $data, $index) {
        $this->klass = $data[$index];
        $this->channel = $data[$index + 1];
        $this->address = $data[$index + 2];
    }

    public function encode(array &$data) {
        $data[] = $this->klass;
        $data[] = $this->channel;
        $data[] = $this->address;
    }

    public function toString() {
        return sprintf("%02x.%02x.%02x", $this->klass, $this->channel, $this->address);
    }
}

class Command {
    public $packetInformation = true;
    public $protocolVersion = 2;
    public $retryCount = 0;
    public $packetType = PacketType::StandBy;
    public $dataType = DataType::Undefined;
    public $packetNumber = 0;
    public $size = 3;

    public function decode(array $data, $index) {
        $this->packetInformation = ((int)$data[$index] & 128) >> 7 == 1;
        $this->protocolVersion = ((int)$data[$index] & 96) >> 5;
        $this->retryCount = ((int)$data[$index] & 24) >> 3;
        $this->packetType = ((int)$data[$index + 1] & 240) >> 4;
        $this->dataType = ((int)$data[$index + 1] & 15);
        $this->packetNumber = $data[$index + 2];
    }

    public function encode(array &$data) {
        $data[] = ((($this->packetInformation ? 1 : 0) << 7) + ($this->protocolVersion << 5) + ($this->retryCount << 3));
        $data[] = (($this->packetType << 4) + $this->dataType);
        $data[] = $this->packetNumber;
    }

    public function toString() {
        return sprintf(
            "{PacketInformation: %d; ProtocolVersion: %d; RetryCount: %d; PacketType: %d; DataType: %d; PacketNumber: %d}",
            $this->packetInformation,
            $this->protocolVersion,
            $this->retryCount,
            $this->packetType,
            $this->dataType,
            $this->packetNumber
        );
    }
}

class Buffer {
    public $size = 0;
    public $data = [];

    public function __construct($size = 0, $data = []) {
        $this->size = $size;
        $this->data = $data;
    }
}

class MessageSet {
    public $messageNumber;
    public $type;
    public $value = 0;
    public $structure = null;
    public $size = 2;

    public function __construct($messageNumber = MessageNumber::Undefiend) {
        $this->messageNumber = $messageNumber;
        $this->type = ($messageNumber === MessageNumber::Undefiend) ? MessageSetType::Enum : (($messageNumber & 0x600) >> 9);
    }

    public static function decode(array $data, $index, $capacity) {
        $messageNumber = ($data[$index] << 8) + $data[$index + 1];
        $set = new MessageSet($messageNumber);
        switch ($set->type) {
            case MessageSetType::Enum:
                $set->value = $data[$index + 2];
                $set->size = 3;
                break;
            case MessageSetType::Variable:
                $set->value = ($data[$index + 2] << 8) | $data[$index + 3];
                $set->size = 4;
                break;
            case MessageSetType::LongVariable:
                $set->value = ($data[$index + 2] << 24) | ($data[$index + 3] << 16) | ($data[$index + 4] << 8) | $data[$index + 5];
                $set->size = 6;
                break;
            case MessageSetType::Structure:
                $set->size = count($data) - $index - 3;
                $set->structure = new Buffer($set->size - 2, array_slice($data, $index + 2, $set->size - 2));
                break;
            default:
                // Unknown type
                break;
        }
        return $set;
    }

    public function encode(array &$data) {
        $data[] = ($this->messageNumber >> 8) & 0xff;
        $data[] = $this->messageNumber & 0xff;
        switch ($this->type) {
            case MessageSetType::Enum:
                $data[] = $this->value;
                break;
            case MessageSetType::Variable:
                $data[] = ($this->value >> 8) & 0xff;
                $data[] = $this->value & 0xff;
                break;
            case MessageSetType::LongVariable:
                $data[] = ($this->value & 0xff);
                $data[] = (($this->value & 0xff00) >> 8);
                $data[] = (($this->value & 0xff0000) >> 16);
                $data[] = (($this->value & 0xff000000) >> 24);
                break;
            case MessageSetType::Structure:
                if ($this->structure && is_array($this->structure->data)) {
                    foreach ($this->structure->data as $byte) {
                        $data[] = $byte;
                    }
                }
                break;
            default:
                // Unknown type
                break;
        }
    }

    public function toString() {
        switch ($this->type) {
            case MessageSetType::Enum:
                return "Enum " . sprintf("0x%04x", $this->messageNumber) . " = " . $this->value;
            case MessageSetType::Variable:
                return "Variable " . sprintf("0x%04x", $this->messageNumber) . " = " . $this->value;
            case MessageSetType::LongVariable:
                return "LongVariable " . sprintf("0x%04x", $this->messageNumber) . " = " . $this->value;
            case MessageSetType::Structure:
                return "Structure #" . sprintf("0x%04x", $this->messageNumber) . " = " . ($this->structure ? $this->structure->size : 0);
            default:
                return "Unknown";
        }
    }
}

class Packet {
    public $sa;
    public $da;
    public $command;
    public $messages = [];

    private static $packetCounter = 0;

    public function __construct() {
        $this->sa = Address::getMyAddress();
        $this->da = new Address();
        $this->command = new Command();
        $this->messages = [];
    }

    public static function create(Address $da, $dataType, $messageNumber, $value) {
        $packet = self::createPartial($da, $dataType);
        $message = new MessageSet($messageNumber);
        $message->value = $value;
        $packet->messages[] = $message;
        return $packet;
    }

    public static function createPartial(Address $da, $dataType) {
        $packet = new Packet();
        $packet->sa = Address::getMyAddress();
        $packet->da = $da;
        $packet->command->packetInformation = true;
        $packet->command->packetType = PacketType::Normal;
        $packet->command->dataType = $dataType;
        $packet->command->packetNumber = self::$packetCounter++;
        return $packet;
    }

    public function decode(array $data) {
        if ($data[0] != 0x32) return 'InvalidStartByte';
        if (count($data) < 16 || count($data) > 1500) return 'UnexpectedSize';
        $size = ($data[1] << 8) | $data[2];
        if ($size + 2 != count($data)) return 'SizeDidNotMatch';
        if ($data[count($data) - 1] != 0x34) return 'InvalidEndByte';

        $crc_actual = self::crc16($data, 3, $size - 4);
        $crc_expected = ($data[count($data) - 3] << 8) | $data[count($data) - 2];
        if ($crc_expected != $crc_actual) return 'CrcError';

        $cursor = 3;
        $this->sa->decode($data, $cursor);
        $cursor += $this->sa->size;
        $this->da->decode($data, $cursor);
        $cursor += $this->da->size;
        $this->command->decode($data, $cursor);
        $cursor += $this->command->size;
        $capacity = $data[$cursor];
        $cursor++;
        $this->messages = [];
        for ($i = 1; $i <= $capacity; ++$i) {
            $set = MessageSet::decode($data, $cursor, $capacity);
            $this->messages[] = $set;
            $cursor += $set->size;
        }
        return 'Ok';
    }

    public function encode() {
        $data = [];
        $data[] = 0x32;
        $data[] = 0; // size
        $data[] = 0; // size
        $this->sa->encode($data);
        $this->da->encode($data);
        $this->command->encode($data);
        $data[] = count($this->messages);
        foreach ($this->messages as $message) {
            $message->encode($data);
        }
        $endPosition = count($data) + 1;
        $data[1] = ($endPosition >> 8) & 0xFF;
        $data[2] = $endPosition & 0xFF;
        $checksum = self::crc16($data, 3, $endPosition - 4);
        $data[] = ($checksum >> 8) & 0xFF;
        $data[] = $checksum & 0xFF;
        $data[] = 0x34;
        return $data;
    }

    public static function crc16(array $data, $startIndex, $length) {
        $crc = 0;
        for ($index = $startIndex; $index < $startIndex + $length; ++$index) {
            $crc ^= ($data[$index] << 8);
            for ($i = 0; $i < 8; $i++) {
                if ($crc & 0x8000) {
                    $crc = ($crc << 1) ^ 0x1021;
                } else {
                    $crc <<= 1;
                }
                $crc &= 0xFFFF; // keep to 16 bits
            }
        }
        return $crc;
    }

    public function toString() {
        $str = "#Packet Src:" . $this->sa->toString() . " Dst:" . $this->da->toString() . " " . $this->command->toString() . "\n";
        foreach ($this->messages as $i => $message) {
            if ($i > 0) $str .= "\n";
            $str .= " > " . $message->toString();
        }
        return $str;
    }
}

?>
