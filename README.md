# Samsung NASA RS485 PHP Connector

Edomi LBS for controlling Samsung HVAC devices (heat pumps, air conditioners) via RS485 using the Samsung NASA protocol.  
Communication runs locally — no cloud required.

## Hardware

- Samsung device with NASA protocol (F1/F2 bus), tested with Samsung AC
- [Waveshare RS485-to-Ethernet converter](https://github.com/sipiyou/waveshare-rs485-php-tcp-connector)

## Cloning

This repository uses a Git submodule for the Waveshare TCP connector class.

```bash
git clone --recurse-submodules https://github.com/sipiyou/samsung-nasa-rs485-php-connector.git
```

If you already cloned without `--recurse-submodules`:

```bash
git submodule update --init
```

## Submodule

| Path | Repository |
|------|------------|
| `php/waveshare/` | [waveshare-rs485-php-tcp-connector](https://github.com/sipiyou/waveshare-rs485-php-tcp-connector) |

## Build (Edomi LBS)

```bash
cd php/edomi
php compile.php
```

Generates `php/edomi/19002625_lbs.php` — the compiled Edomi LBS ready for import.

## Installation (Edomi)

1. Import `19002625_lbs.php` into Edomi
2. Set E2 (Waveshare IP) and E3 (Port, default 4196)
3. Set E1 = 1 to start

See the [Wiki](https://github.com/sipiyou/samsung-nasa-rs485-php-connector/wiki) for detailed setup instructions.

## Community

Protocol research based on:  
https://github.com/omerfaruk-aran/esphome_samsung_hvac_bus
