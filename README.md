

<h1 align="center">Scramble Extensions</h1>

<p>Scramble Extensions is a package that contains different extensions for the package Dedoc/Scramble. This extensions are meant to adapt the documentaion package to light it's way of coding.</p>

## Install

Requirements:
  Laravel >= 8.17
  PHP >= 8.1.0
  Composer


* `brew install php@8.1 composer` Mac OS X with brew
* `apt-get install php8.1` Ubuntu with apt-get (use sudo if is necessary)

This step is not necessary when you use Docker.

### Development Installation

1. Clone GitHub repo for this project locally:

    ```
    git clone git@github.com:Light-it-labs/scramble-extensions
    ```

2. cd into your project and create a copy of your .env file

    ```
    cd scramble-extensions
    ```

3. Install required dependencies with
  ```
  composer install
  ```

## Install in Projects

For the moment, Lagger isn't available in packagist.org (composer package library), so in order to install it in a project,
we need to add the following lines to our project's composer.json
  ```
  "repositories": [
    {
      "type": "vcs",
      "url": "https://github.com/Light-it-labs/scramble-extensions"
    }
  ]
  ```

  After that, composer will also look for packages in this repo and we can execute the following command.

  ```
  composer require lightit/scrambleExtensions
  ```

## Scramble Extensions Usage

##Include Extensions to Scramble

<p>To use the extensions that the package offers you will have to modify Scramble's config file. In the </p>

###Example
```
'servers' => null,

    'middleware' => [
        'web',
        'auth:web',
    ],

    'extensions' => LightIt\ScrambleExtensions\LightitScrambleExtensions::getAllExtensions(),
```
