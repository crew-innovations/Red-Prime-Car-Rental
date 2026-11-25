<?php

namespace Tests\Unit;

use App\Support\Ocr\TextParser;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** 19 Nov: details found in the text read on the staff computer (no outside service). */
class OcrTextParserTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-19 09:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_passport_machine_zone_wins_and_is_checked(): void
    {
        $text = "PASSPORT\nP<UTOERIKSSON<<ANNA<MARIA<<<<<<<<<<<<<<<<<<<\nL898902C36UTO7408122F1204159ZE184226B<<<<<10";
        $r = TextParser::customer([['type' => 'passport', 'text' => $text]]);
        $this->assertSame('L898902C3', $r['passport_number']);
        $this->assertSame('1974-08-12', $r['date_of_birth']);
        $this->assertSame('Anna Maria Eriksson', $r['name']);

        // One wrong digit: the machine zone is not trusted.
        $bad = str_replace('L898902C36', 'L898902C37', $text);
        $this->assertArrayNotHasKey('passport_number', TextParser::customer([['type' => 'passport', 'text' => $bad]]));
    }

    public function test_real_reader_slips_in_the_machine_zone_are_fixed(): void
    {
        // Text as Tesseract really returned it: "<" read as "L", "O" read as "0".
        $r = TextParser::customer([['type' => 'passport', 'text' => "P<UTOERIKSSON<<ANNA<MARIA<<<<<<LLLLLLLLLLLLL\nL898902C36UT07408122F1204159ZE184226B<<<<<10\n"]]);
        $this->assertSame('L898902C3', $r['passport_number']);
        $this->assertSame('Anna Maria Eriksson', $r['name']);
    }

    public function test_emirates_id_and_driving_licence_labels(): void
    {
        $r = TextParser::customer([
            ['type' => 'emirates_id', 'text' => "UNITED ARAB EMIRATES\nName: AHMED ALI KHAN\nNationality: Pakistan\nID Number 784 1990 1234567 1\nDate of Birth 01/02/1990\nExpiry Date 05/06/2028"],
            ['type' => 'driving_licence', 'text' => "Driving License\nUnited Arab Emirates\nLicense No. 1234567\nDate of Birth 01/02/1990\nIssue Date\n10/03/2015\nExpiry Date 10/03/2030\nTraffic Code No. 50123456"],
        ]);
        $this->assertSame('784-1990-1234567-1', $r['id_number']);
        $this->assertSame('2028-06-05', $r['id_expires_on']);
        $this->assertSame('Ahmed Ali Khan', $r['name']);
        $this->assertSame('Pakistan', $r['nationality']);
        $this->assertSame('1990-02-01', $r['date_of_birth']);
        $this->assertSame('1234567', $r['licence_number']);
        $this->assertSame('2015-03-10', $r['licence_issued_on']);
        $this->assertSame('2030-03-10', $r['licence_expires_on']);
        $this->assertSame('United Arab Emirates', $r['licence_country']);
    }

    public function test_receipt_total_vat_trn_and_number(): void
    {
        $r = TextParser::receipt(["ENOC Service Station\nTAX INVOICE\nTRN: 100123456700003\nInvoice No: INV-8891\nDate: 18/11/2026\nPetrol Super 98 45.20 L 132.00\nSub Total 125.71\nVAT 5% 6.29\nTotal AED 132.00"]);
        $this->assertSame('ENOC Service Station', $r['vendor']);
        $this->assertSame('100123456700003', $r['trn']);
        $this->assertSame('INV-8891', $r['invoice_number']);
        $this->assertSame('2026-11-18', $r['date']);
        $this->assertSame(132.0, $r['amount']);
        $this->assertSame(6.29, $r['vat_amount']);
    }

    public function test_one_scan_fills_only_the_matching_document_fields(): void
    {
        $this->assertSame('passport', TextParser::guessType("PASSPORT\nPassport No: AB1234567"));
        $this->assertSame('emirates_id', TextParser::guessType('Emirates ID 784-1990-1234567-1'));
        $this->assertSame('driving_licence', TextParser::guessType("Driving Licence\nLicence No: 12345678"));

        $r = TextParser::customer([['type' => 'scan', 'text' => "PASSPORT\nName: Salim Hasson\nPassport No: AB1234567\nNationality: Israel"]]);
        $this->assertSame('AB1234567', $r['passport_number']);
        $this->assertSame('Salim Hasson', $r['name']);
        $this->assertArrayNotHasKey('licence_number', $r);
        $this->assertArrayNotHasKey('id_number', $r);
    }
}
