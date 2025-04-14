# Commission Calculator

This project is a refactored PHP application designed to calculate commissions for financial transactions based on transaction data and external API lookups.

## Core Functionality

* Reads transaction data (BIN, amount, currency) line-by-line from an input file (JSON format).
* Looks up the issuing country based on the BIN using `BinListLookup` service (via `BinLookupInterface`).
* Fetches exchange rates relative to EUR using `ExchangeRatesApi` service (via `CurrencyRateInterface`).
* Calculates commission using `CommissionCalculator`:
    * Converts amount to EUR.
    * Applies 1% (EU country) or 2% (Non-EU country) rate.
    * Rounds the final commission amount up to the nearest cent.
* Outputs the calculated commission for each valid transaction.

## API Challenges & Solutions

Handling external API limitations was key:

* **BIN Lookup (binlist.net free tier):** Faced extreme rate limits (5/hr). Implemented long-term caching (30 days) for BIN results. **Note:** For real use, an alternative BIN provider or paid plan is highly recommended.
* **Exchange Rates (exchangerate.host free tier):** This API provides rates based only on USD. The solution involves fetching USD-based rates (specifically `USD->EUR` and `USD->Target`) and calculating the required `EUR->Target` rate (`Rate(USD/Target) / Rate(USD/EUR)`). The full API response is cached for 1 hour to minimize calls. (100 call per month)

* I am also providing API key from **exchangerate.host**: `9a89a609f35e35eb312b8fa03cd16cef`, - it's from free plan.

## Key Features

* PHP 8.3 (Tested on 8.4)
* Composer Dependency Management
* Dependency Injection (Custom Container) & Interfaces
* Caching (`symfony/cache`)
* Logging (`monolog/monolog`)
* Configuration via `.env`
* Unit & Functional Tests (PHPUnit)
* Docker Support (`Dockerfile`, `docker-compose.yml`)

## Setup & Usage

**Prerequisites:** PHP 8.3+, Composer, Docker & Docker Compose (Optional)

1.  **Clone:**
    ```bash
    git clone <your-repository-url>
    cd <project-directory>
    ```
2.  **Install:**
    ```bash
    composer install
    ```
3.  **Configure:**
    * Copy `.env.example` to `.env`: `cp .env.example .env`
    * Edit `.env`: Fill in `BIN_LOOKUP_URL`, `EXCHANGE_RATES_URL`, and the required `EXCHANGE_RATES_API_KEY`. Adjust `LOG_LEVEL` if needed.
4.  **Cache Directory:**
    ```bash
    mkdir cache
    chmod -R 775 cache
    ```
5.  **Input File:** Create `input.txt` (or other name) in the project root with one JSON transaction per line:
    * `{"bin":"123456","amount":"100.00","currency":"USD"}`

**Running the App:**

* **Directly:**
    ```bash
    php app.php input.txt
    ```
* **The Output with current input.txt will look like this:**
    ```bash
    1
    0.45
    1.24
    2.3
    23.25
    ```
* **Via Docker (Recommended):**
    ```bash
    docker-compose build

    # Ensure input.txt is in the project root
    docker-compose run --rm app php app.php input.txt
    ```

**Running Tests (Via Docker):**

* **All Tests:**
    ```bash
    docker compose run --rm app vendor/bin/phpunit
    ```