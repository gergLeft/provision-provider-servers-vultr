# Upmind Provision Providers - Servers

[![Latest Version on Packagist](https://img.shields.io/packagist/v/upmind/provision-provider-servers.svg?style=flat-square)](https://packagist.org/packages/upmind/provision-provider-servers)

This provision category contains the common functions used in provisioning flows for servers on various popular cloud platforms.

- [Installation](#installation)
- [Usage](#usage)
  - [Quick-start](#quick-start)
- [Supported Providers](#supported-providers)
- [Functions](#functions)
  - [create()](#create)
  - [getInfo()](#getInfo)
  - [changeRootPassword()](#changeRootPassword)
  - [resize()](#resize)
  - [reinstall()](#reinstall)
  - [reboot()](#reboot)
  - [shutdown()](#shutdown)
  - [powerOn()](#powerOn)
  - [terminate()](#terminate)
- [Changelog](#changelog)
- [Contributing](#contributing)
- [Credits](#credits)
- [License](#license)
- [Upmind](#upmind)

## Installation

```bash
composer require upmind/provision-provider-servers
```

## Usage

This library makes use of [upmind/provision-provider-base](https://packagist.org/packages/upmind/provision-provider-base) primitives which we suggest you familiarize yourself with by reading the usage section in the README.

### Quick-start

The easiest way to see this provision category in action and to develop/test changes is to install it in [upmind/provision-workbench](https://github.com/upmind-automation/provision-workbench#readme).

Alternatively you can start using it for your business immediately with [Upmind.com](https://upmind.com/start) - the ultimate web hosting billing and management solution.

## Supported Providers

The following providers are currently implemented:
  - [Linode](https://www.linode.com/docs/api/)

## Functions

### create()

Creates and starts a server and returns the `instance_id` used to identify the server in subsequent functions.

### getInfo()

Gets information about a server such as its label, current state (running/rebooting etc), image (e.g., ubuntu), size and region.

### changeRootPassword()

Update the root password used to SSH into a server.

### resize()

Redeploy an existing server with a different resource allocation.

### reinstall()

Reinstall (wipe/reset) an existing server server using a particular image.

### reboot()

Reboot (shutdown then power-on) a running server.

### shutdown()

Shut down a running server.

### powerOn()

Boot a powered-off server.

### terminate()

Terminate (delete) an existing server.

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Credits

 - [Harry Lewis](https://github.com/uphlewis)
 - [All Contributors](../../contributors)

## License

GNU General Public License version 3 (GPLv3). Please see [License File](LICENSE.md) for more information.

## Upmind

Sell, manage and support web hosting, domain names, ssl certificates, website builders and more with [Upmind.com](https://upmind.com/start) - the ultimate web hosting billing and management solution.
