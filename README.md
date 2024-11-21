# Registry Migration: RootPanel to Namingo

## Overview

This script facilitates the migration of all objects from a RootPanel registry to the Namingo registry system. It is designed to handle the import process by reading data from RootPanel's SQL export and inserting the relevant information into the Namingo database.

## Installation

1. Clone the Repository: Clone this repository to your local machine.

2. Install Dependencies: Navigate to the directory of the cloned repository and run ```composer install```.

3. Configure Database Connection: Rename ```config.php.dist``` to ```config.php```. Edit this file to include your Namingo database connection details.

## Prepare Data Files

Place your RootPanel SQL export files in the same directory as the script.

## Running the Script

Execute the script with the following command:

```bash
php import.php path_to_sql_file.sql
```

## Troubleshooting

In case of issues during the import process, you may need to truncate (empty) your Namingo database and attempt the import again. Execute the cleanup script with the following command:

```bash
php cleanup.php
```