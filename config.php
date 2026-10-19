<?php
date_default_timezone_set('Europe/Rome');

const LOGGER_IP = '192.168.1.30';
const LOGGER_SN = 123456789;   // seriale della chiavetta necessario per leggere i dati
const SLAVE_ID  = 1;
      // secondi tra una lettura e l'altra
const SEGNO_RETE = 1;           // metti -1 se Immissione/Prelievo risultano invertiti rispetto all'app
const SEGNO_BATT = 1;   
const INTERVALLO = 10;          // secondi tra una lettura e l'altra (dati live)
const DURATA     = 55;          // quanto resta in esecuzione ogni avvio da cron (secondi)
const DB_OGNI    = 10;          // ogni quanti secondi salvare una riga nello storico PRIMA ERA A 30
const CONSERVA_GIORNI = 400;      // cancella i dati più vecchi di N giorni (0 = conserva tutto)

// Database: SQLite non richiede nessuna configurazione.
// Per MySQL: 'mysql:host=localhost;dbname=energia;charset=utf8mb4' con utente e password
const DB_DSN  = 'mysql:host=localhost;dbname=dbnome;charset=utf8mb4';
const DB_USER = 'utente';
const DB_PASS = 'passwrd';

const FILE_DATI = __DIR__ . '/data.json';

const FILE_LOCK  = __DIR__ . '/poller.lock';// chiave => [indirizzo, n. registri, divisore, con segno]
$REGISTRI = [
// Le chiavi diventano anche le colonne della tabella "misure"
    'fv'        => [0x05C4, 1, 10,  false],
    'casa'      => [0x04AF, 1, 100, false],
    'rete'      => [0x0488, 1, 100, true],
    'batt_kw'   => [0x0606, 1, 100, true],
    'batt_pct'  => [0x0608, 1, 1,   false],
    'batt_v'    => [0x0604, 1, 10,  false],
    'fv_day'    => [0x0684, 2, 100, false],
    'casa_day'  => [0x0688, 2, 100, false],
    'prel_day'  => [0x068C, 2, 100, false],
    'imm_day'   => [0x0690, 2, 100, false],
    'bc_day'    => [0x0694, 2, 100, false],
    'bs_day'    => [0x0698, 2, 100, false],
];
