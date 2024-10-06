<?php
// Baca file .env
$env        = file_get_contents(__DIR__ . "/.env");
$lines      = explode(PHP_EOL, $env);
$env        = [];
foreach ($lines as $line) {
    if(strpos($line, "=")){
        $text   = explode("=", $line);
        $key    = isset($text[0]) ? $text[0] : '';
        $value  = isset($text[1]) ? $text[1] : '';
        if ($key != '') {
            $env[$key] = $value;
        }
    }
}

// Koneksi ke Mysql database
$koneksi    = new mysqli($env['mysql_hostname'], $env['mysql_username'], $env['mysql_password'], $env['mysql_database']);
if ($koneksi->connect_error) {
    die("Koneksi bermasalah. Pastikan konfigurasi Mysql server anda benar.");
}

function getUpdates($koneksi)
{
    global $env;

    // Konfigurasi Email Client (Manual / Gmail)
    $server     = "{" . $env['email_server'] . "/pop3/notls}INBOX";
    if(strtolower($env['email_client']) == "gmail"){
        $server = "{imap.gmail.com:993/imap/ssl}INBOX";
    }

    $inbox      = imap_open($server, $env['email_address'], $env['email_password']);
    $jumlah     = imap_num_msg($inbox);
    $start      = $jumlah - 10;
    if ($jumlah > 0) {
        while ($start <= $jumlah) {
            $header = imap_headerinfo($inbox, $start);
            $subject = $header->subject;
            $body   = imap_fetchbody($inbox, $start, 1);
            $title  = $header->subject;
            $from   = $header->fromaddress;
            $sender = $header->senderaddress;
            $toaddr = $header->toaddress;
            $date   = date('Y-m-d H:i:s', strtotime($header->date));
            $id     = md5($title . $date . $from);
            $periode = date("ym", strtotime($date));

            // Buat table email_history per bulan
            $sql    = "CREATE TABLE IF NOT EXISTS email_history_$periode like email_history";
            $koneksi->query($sql);

            // Cek apakah email sudah ada di database
            $sql    = "SELECT * FROM email_history_$periode where id='$id'";
            $res    = $koneksi->query($sql);
            $list_mail = [];
            if ($res->num_rows > 0) {
                while ($d = $res->fetch_assoc()) {
                    $list_mail[] = $d['id'];
                }
            }
            if (!in_array($id, $list_mail)) {
                $text   = "Ada Email Baru :\n";
                $text   .= "💬 : " . $subject . "\n";
                $text   .= "🙍‍♂️ : " . $from . "\n";
                $text   .= "📅 : " . date('d M Y, H:i:s', strtotime($date)) . "\n";
                sendMessage($text);

                $stmt = $koneksi->prepare("INSERT INTO email_history_$periode (`id`, `subject`, `sender`, `receiver`, `date`, `body`) values (?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("ssssss", $id, $subject, $sender, $toaddr, $date, $body);
                $stmt->execute();
            
                echo "* Berhasil Kirim Notif, Nomor Index : $start\n";
            }

            $start++;
        }
    }
}

function sendMessage($text)
{
    global $env;
    $url        = "https://api.telegram.org/bot" . $env['bot_token'] . "/sendMessage";
    $handle     = curl_init($url);
    $parameters = [
        'chat_id' => $env['telegram_id'],
        'text' => $text
    ];

    curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($handle, CURLOPT_TIMEOUT, 60);
    curl_setopt($handle, CURLOPT_POSTFIELDS, $parameters);

    $response   = curl_exec($handle);

    if ($response === false) {
        curl_close($handle);
        return false;
    } else {
        $response = json_decode($response, true);
        curl_close($handle);
        return $response;
    }
}

$text = "TELEGRAM MAIL BOT\n";
$text .= "Author : Wahyu Hidayat\n";
$text .= "Github : https://github.com/wahyuhidayattz\n";
$text .= "===============================\n";
$text .= "LOG    :\n";
$text .= "===============================\n";
$sleep = isset($env['sleep_time']) ? $env['sleep_time'] : 60;

echo $text;

while(true){
    getUpdates($koneksi);
    echo "(*) Refreshing...\n";
    sleep($sleep);
}