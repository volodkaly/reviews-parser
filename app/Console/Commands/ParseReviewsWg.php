<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Facebook\WebDriver\Chrome\ChromeOptions;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverExpectedCondition;

class ParseReviewsWg extends Command
{
    protected $signature = 'parse:wg';
    protected $description = 'Get up to 100 pages of reviews from Astropay Trustpilot and save to a file';

    public function handle()
    {
        // 1. Налаштування Chrome
        $options = new ChromeOptions();
        $options->addArguments(['--headless', '--disable-gpu']); // без GUI

        $capabilities = DesiredCapabilities::chrome();
        $capabilities->setCapability(ChromeOptions::CAPABILITY, $options);

        // 2. Підключення до WebDriver
        $driver = RemoteWebDriver::create('http://localhost:9515', $capabilities);

        // 3. Відкриваємо сторінку
        $driver->get('https://www.trustpilot.com/review/wg.casino');

        // 4. Підготовка файлу для збереження
        $filePath = storage_path('wg_reviews_all.txt');
        file_put_contents($filePath, ""); // очищаємо файл перед записом

        $hasNext = true;
        $pages = 0;
        $maxPages = 1000; // ліміт кількість сторінок

        do {
            // 5. Чекаємо, поки відгуки завантажаться
            $driver->wait(3)->until(
                WebDriverExpectedCondition::presenceOfElementLocated(
                    WebDriverBy::cssSelector('section.styles_reviewListContainer__2bg_p')
                )
            );

            // 6. Отримуємо текст відгуків на поточній сторінці
            $reviewsContainer = $driver->findElement(
                WebDriverBy::cssSelector('section.styles_reviewListContainer__2bg_p')
            );
            $reviewsText = $reviewsContainer->getText();
            file_put_contents($filePath, $reviewsText . "\n\n---\n\n", FILE_APPEND);

            $pages++;
            if ($pages >= $maxPages) {
                $hasNext = false;
                break;
            }

            // 7. Стабільний клік на "Next" з обробкою StaleElement
            try {
                $nextButton = $driver->findElement(
                    WebDriverBy::cssSelector('a[data-pagination-name="pagination-button-next"]')
                );

                if ($nextButton->isDisplayed() && $nextButton->isEnabled()) {
                    // Сховати банер
                    $driver->executeScript("
            let banner = document.querySelector('.onetrust-pc-dark-filter');
            if (banner) { banner.style.display = 'none'; }
        ");

                    // Прокрутка і клік через JS
                    $driver->executeScript("arguments[0].scrollIntoView(true);", [$nextButton]);
                    sleep(0.1);
                    $driver->executeScript("arguments[0].click();", [$nextButton]);

                    sleep(0.1); // чекаємо нові відгуки
                    $hasNext = true;
                } else {
                    $hasNext = false;
                }
            } catch (\Facebook\WebDriver\Exception\StaleElementReferenceException $e) {
                sleep(0.1);
                continue;
            } catch (\Facebook\WebDriver\Exception\NoSuchElementException $e) {
                $hasNext = false;
            }


        } while ($hasNext && $nextButton);

        $this->info("Зібрано $pages сторінок відгуків. Дані збережено у файл: $filePath");

        // 8. Закриваємо драйвер
        $driver->quit();
    }
}
