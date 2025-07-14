    <?php
    use PHPMailer\PHPMailer\PHPMailer;
    use PHPMailer\PHPMailer\SMTP;
    use PHPMailer\PHPMailer\Exception;
    
    require_once 'config.php';
    require "PHPMailer/PHPMailer/src/Exception.php";
    require "PHPMailer/PHPMailer/src/PHPMailer.php";
    require "PHPMailer/PHPMailer/src/SMTP.php";

    // === CONFIG ===
    $token = BOT_TOKEN;
    $website = BOT_API_URL;
   $db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);    

    if ($db->connect_error) {
        die("Database connection failed: " . $db->connect_error);
    }

    // === CORE ===
    $update = json_decode(file_get_contents("php://input"), true);
    $chat_id =
        $update["message"]["chat"]["id"] ??
        ($update["callback_query"]["message"]["chat"]["id"] ?? null);
    $text = $update["message"]["text"] ?? null;
    $callback_data = $update["callback_query"]["data"] ?? null;

    if (!$chat_id) {
        echo "🤖 Bot is running. No Telegram data received.";
        exit();
    }

    $session_file = "session_$chat_id.txt";
    $session = file_exists($session_file)
        ? json_decode(file_get_contents($session_file), true)
        : [];

    // === ROUTING ===
    if ($text) {
        if ($text === "/start") {
            $session["data"] = $update;
            $first_name = $session["data"]["message"]["from"]["first_name"];
            $last_name = $session["data"]["message"]["from"]["last_name"];
            $username = $update["message"]["from"]["username"] ?? "";
            $telegram_id = $update["message"]["from"]["id"] ?? "";

            $session["first_name"] = $first_name;
            $session["last_name"] = $last_name;
            $session["username"] = $username;
            $session["telegram_id"] = $telegram_id;

            $stmt = $db->prepare(
                "SELECT telegram_id, verified FROM user WHERE telegram_id = ?"
            );
            $stmt->bind_param("i", $telegram_id);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($user = $result->fetch_assoc()) {
                if ($user["verified"]) {
                    sendInlineMenu(
                        $chat_id,
                        "👋 Welcome back $first_name $last_name!"
                    );
                } else {
                    $session["step"] = "awaiting_email_auth";
                    sendMessage(
                        $chat_id,
                        "👋 Hi $first_name, please verify your email to continue:"
                    );
                }
            } else {
                $session["step"] = "awaiting_email_auth";
                sendMessage(
                    $chat_id,
                    "👋 Welcome $first_name! Please enter your registered email to authenticate:"
                );
            }
            saveSession($session_file, $session);
            $stmt->close();
            exit();
        } else {
            handleTextMessage($text, $chat_id, $session, $session_file, $db);
        }
        exit();
    }

    if ($callback_data) {
        handleCallback(
            $callback_data,
            $chat_id,
            $session,
            $session_file,
            $update,
            $db
        );
        exit();
    }

    // === CALLBACK HANDLER ===
    function handleCallback(
        $data,
        $chat_id,
        $session,
        $session_file,
        $update,
        $db
    ) {
        switch ($data) {
            case "Main_menu":
                sendInlineMenu(
                    $chat_id,
                    "👋 Welcome to VHM Global ! Choose an option:"
                );
                break;

            case "profile":
                sendInlineKeyboard(
                    $chat_id,
                    "Please select the option below:",
                    [
                        [
                            [
                                "text" => "📩Update Email",
                                "callback_data" => "update_email",
                            ],
                        ],
                        [
                            [
                                "text" => "📞Update Phone Number",
                                "callback_data" => "update_phone_number",
                            ],
                        ],
                        [
                            [
                                "text" => "🔙 Main Menu",
                                "callback_data" => "Main_menu",
                            ],
                        ],
                    ]
                );
                break;

            case "accounts":
                sendInlineKeyboard(
                    $chat_id,
                    "Please select the option below:",
                    [
                        [
                            [
                                "text" => "✅ Check Balance",
                                "callback_data" => "check_balance",
                            ],
                        ],
                        [
                            [
                                "text" => "🔙 Main Menu",
                                "callback_data" => "Main_menu",
                            ],
                        ],
                    ]
                );
                break;

            case "re_auth":
                $session["step"] = "awaiting_email_auth";
                saveSession($session_file, $session);
                sendMessage(
                    $chat_id,
                    "👋 Hi please verify your email to continue:"
                );
                break;

            case "update_email":
                $session["step"] = "awaiting_email";
                saveSession($session_file, $session);
                answerCallback($update["callback_query"]["id"]);
                sendMessage($chat_id, "Enter Your Current Email :");
                break;

            case "update_phone_number":
                $session["step"] = "awaiting_number";
                saveSession($session_file, $session);
                answerCallback($update["callback_query"]["id"]);
                sendMessage($chat_id, "Enter your Current Phone Number :");
                break;

            case "check_balance":
                $session["step"] = "awaiting_account";
                saveSession($session_file, $session);
                answerCallback($update["callback_query"]["id"]);
                sendMessage(
                    $chat_id,
                    "🔢 Please enter your bank account number:"
                );
                break;

            default:
                sendMessage($chat_id, "❓ Unknown option selected.");
        }

        saveSession($session_file, $session);
    }

    // === TEXT MESSAGE HANDLER ===
    function handleTextMessage($text, $chat_id, $session, $session_file, $db)
    {
        switch ($session["step"]) {
            case "awaiting_account":
                $stmt = $db->prepare(
                    "SELECT account_number FROM accounts WHERE account_number = ?"
                );
                $stmt->bind_param("s", $text);
                $stmt->execute();
                $stmt->store_result();

                if ($stmt->num_rows === 1) {
                    $session["account"] = $text;
                    $session["step"] = "awaiting_pin";
                    saveSession($session_file, $session);
                    sendMessage(
                        $chat_id,
                        "🔐 Account verified. Please enter your 4-digit PIN:"
                    );
                } else {
                    sendMessage(
                        $chat_id,
                        "⚠️ Account number not found. Please try again:"
                    );
                }

                $stmt->close();
                break;

            case "awaiting_pin":
                $account = $session["account"];
                $stmt = $db->prepare(
                    "SELECT balance FROM accounts WHERE account_number = ? AND pin = ?"
                );
                $stmt->bind_param("ss", $account, $text);
                $stmt->execute();
                $stmt->bind_result($balance);

                if ($stmt->fetch()) {
                    sendMessage(
                        $chat_id,
                        "✅ Your account balance is: ₹$balance"
                    );
                    $session["step"] = null;
                    saveSession($session_file, $session);
                    $session["last_checked"] = date("Y-m-d H:i:s");
                    sendInlineMenu($chat_id, "What would you like to do next?");
                } else {
                    sendMessage($chat_id, "❌ Invalid PIN. Please try again:");
                }

                $stmt->close();
                break;

            case "awaiting_email":
                $stmt = $db->prepare("SELECT email FROM user WHERE email = ?");
                $stmt->bind_param("s", $text);
                $stmt->execute();
                $stmt->store_result();

                if ($stmt->num_rows === 1) {
                    $verification_code = rand(100000, 999999); // Generate 6-digit code
                    $session["email"] = $text;
                    $session["verification_code"] = $verification_code;
                    $session["step"] = "awaiting_email_verification";
                    saveSession($session_file, $session);

                    if (sendVerificationEmail($text, $verification_code)) {
                        sendMessage(
                            $chat_id,
                            "📩 Code sent to email. Please enter it:"
                        );
                    } else {
                        sendMessage(
                            $chat_id,
                            "❌ Failed to send email. Try later."
                        );
                    }
                } else {
                    sendMessage(
                        $chat_id,
                        "⚠️ Email not found. Please try again:"
                    );
                }

                $stmt->close();
                break;
            case "awaiting_email_verification":
                if ($text == $session["verification_code"]) {
                    $session["step"] = "awaiting_newemail";
                    saveSession($session_file, $session);
                    sendMessage(
                        $chat_id,
                        "✅ Verification successful. Please enter your new email address:"
                    );
                } else {
                    sendMessage(
                        $chat_id,
                        "❌ Incorrect verification code. Please try again:"
                    );
                }
                break;

            case "awaiting_newemail":
                $email = $session["email"];
                $updateStmt = $db->prepare(
                    "UPDATE user SET email = ? WHERE email = ?"
                );
                $updateStmt->bind_param("ss", $text, $email);

                if ($updateStmt->execute()) {
                    sendMessage(
                        $chat_id,
                        "✅ Email updated successfully to: $text"
                    );
                    $session["step"] = null;
                    saveSession($session_file, $session);
                    $session["last_checked"] = date("Y-m-d H:i:s");
                    sendInlineMenu($chat_id, "What would you like to do next?");
                } else {
                    sendMessage(
                        $chat_id,
                        "❌ Failed to update email. Please try again."
                    );
                }
                $updateStmt->close();
                break;

            case "awaiting_number":
                $stmt = $db->prepare(
                    "SELECT email FROM user WHERE phone_number = ?"
                );
                $stmt->bind_param("s", $text);
                $stmt->execute();
                $stmt->store_result();

                if ($stmt->num_rows === 1) {
                    $stmt->bind_result($email);
                    $stmt->fetch();
                    $verification_code = rand(100000, 999999);
                    $session["phone"] = $text;
                    $session["associated_email"] = $email;
                    $session["verification_code"] = $verification_code;
                    $session["step"] = "awaiting_phone_verification";
                    saveSession($session_file, $session);

                    if (sendVerificationEmail($email, $verification_code)) {
                        sendMessage(
                            $chat_id,
                            "📩 Verification code sent to associated email: $email\nPlease enter the code:"
                        );
                    } else {
                        sendMessage(
                            $chat_id,
                            "❌ Failed to send email. Please try again."
                        );
                    }
                } else {
                    sendMessage(
                        $chat_id,
                        "⚠️ Phone number not found. Please try again:"
                    );
                }

                $stmt->close();
                break;

            case "awaiting_phone_verification":
                if ($text == $session["verification_code"]) {
                    $session["step"] = "awaiting_newphone";
                    saveSession($session_file, $session);
                    sendMessage(
                        $chat_id,
                        "✅ Verification successful. Please enter your new phone number:"
                    );
                } else {
                    sendMessage(
                        $chat_id,
                        "❌ Incorrect verification code. Please try again:"
                    );
                }
                break;

            case "awaiting_newphone":
                $oldPhone = $session["phone"];
                $newPhone = $text;

                $updateStmt = $db->prepare(
                    "UPDATE user SET phone_number = ? WHERE phone_number = ?"
                );
                $updateStmt->bind_param("ss", $newPhone, $oldPhone);

                if ($updateStmt->execute()) {
                    sendMessage(
                        $chat_id,
                        "✅ Phone number updated successfully to: $newPhone"
                    );
                    $session["step"] = null;
                    $session["last_checked"] = date("Y-m-d H:i:s");
                    saveSession($session_file, $session);
                    sendInlineMenu($chat_id, "What would you like to do next?");
                } else {
                    sendMessage(
                        $chat_id,
                        "❌ Failed to update phone number. Please try again."
                    );
                }

                $updateStmt->close();
                break;

            case "awaiting_email_auth":
                $stmt = $db->prepare("SELECT email FROM user WHERE email = ?");
                $stmt->bind_param("s", $text);
                $stmt->execute();
                $stmt->store_result();

                if ($stmt->num_rows === 1) {
                    $verification_code = rand(100000, 999999); // Generate 6-digit code
                    $session["email"] = $text;
                    $session["verification_code"] = $verification_code;
                    $session["step"] = "awaiting_email_verification_auth";
                    saveSession($session_file, $session);

                    if (sendVerificationEmail($text, $verification_code)) {
                        sendMessage(
                            $chat_id,
                            "📩 Code sent to email. Please enter it:"
                        );
                    } else {
                        sendMessage(
                            $chat_id,
                            "❌ Failed to send email. Try later."
                        );
                    }
                } else {
                    sendInlineMenuauth(
                        $chat_id,
                        "❌ Email not found in our system.\nPlease sign up using the link below:"
                    );
                    $session["step"] = null;
                }
                break;

            case "awaiting_email_verification_auth":
                if ($text == $session["verification_code"]) {
                    $email = $session["email"];
                    $telegram_id = $session["telegram_id"] ?? 0;
                    $username = $session["username"] ?? "";
                    $first_name = $session["first_name"] ?? "";
                    $last_name = $session["last_name"] ?? "";

                    // Update user record in the database
                    $stmt = $db->prepare(
                        "UPDATE user SET telegram_id = ?, username = ?, verified = 1 WHERE email = ?"
                    );
                    $stmt->bind_param("iss", $telegram_id, $username, $email);
                    $stmt->execute();
                    $stmt->close();

                    // Clear session step
                    $session["step"] = null;
                    saveSession($session_file, $session);

                    sendMessage(
                        $chat_id,
                        "✅ Verification successful. Telegram account linked."
                    );
                    sendInlineMenu($chat_id, "What would you like to do next?");
                } else {
                    sendMessage(
                        $chat_id,
                        "❌ Incorrect verification code. Please try again:"
                    );
                }
                break;

            default:
                $update = json_decode(file_get_contents("php://input"), true);
                $chat_id =
                    $update["message"]["chat"]["id"] ??
                    ($update["callback_query"]["message"]["chat"]["id"] ??
                        null);
                $text = $update["message"]["text"] ?? null;
                $callback_data = $update["callback_query"]["data"] ?? null;
                $session_file = "session_$chat_id.txt";
                $session = file_exists($session_file)
                    ? json_decode(file_get_contents($session_file), true)
                    : [];
                $session["data"] = $update;
                $first_name = $session["data"]["message"]["from"]["first_name"];
                $last_name = $session["data"]["message"]["from"]["last_name"];
                $username = $update["message"]["from"]["username"] ?? "";
                $telegram_id = $update["message"]["from"]["id"] ?? "";

                $session["first_name"] = $first_name;
                $session["last_name"] = $last_name;
                $session["username"] = $username;
                $session["telegram_id"] = $telegram_id;

                $stmt = $db->prepare(
                    "SELECT telegram_id, verified FROM user WHERE telegram_id = ?"
                );
                $stmt->bind_param("i", $telegram_id);
                $stmt->execute();
                $result = $stmt->get_result();
                if ($user = $result->fetch_assoc()) {
                    if ($user["verified"]) {
                        sendInlineMenu(
                            $chat_id,
                            "👋 Welcome back $first_name $last_name!"
                        );
                    } else {
                        $session["step"] = "awaiting_email_auth";
                        saveSession($session_file, $session);
                        sendMessage(
                            $chat_id,
                            "👋 Hi $first_name, please verify your email to continue:"
                        );
                    }
                } else {
                    $session["step"] = "awaiting_email_auth";
                    saveSession($session_file, $session);
                    sendMessage(
                        $chat_id,
                        "👋 Welcome $first_name! Please enter your registered email to authenticate:"
                    );
                }
                saveSession($session_file, $session);
                $stmt->close();
                exit();
        }

        // saveSession($session_file, $session);
    }

    //app mail passowrd :- sjgx edik vosg cyrd

    // === UTILITY FUNCTIONS ===

    function sendMessage($chat_id, $text, $keyboard = null)
    {
        global $website;
        $payload = [
            "chat_id" => $chat_id,
            "text" => $text,
            "parse_mode" => "Markdown",
        ];

        if ($keyboard) {
            $payload["reply_markup"] = json_encode($keyboard);
        }

        file_get_contents(
            $website . "/sendMessage?" . http_build_query($payload)
        );
    }

    function sendInlineMenu($chat_id, $text)
    {
        sendInlineKeyboard($chat_id, $text, [
            [
                ["text" => " 👤Profile", "callback_data" => "profile"],
                ["text" => "💲Accounts", "callback_data" => "accounts"],
            ],
        ]);
    }

    function sendInlineMenuauth($chat_id, $text)
    {
        sendInlineKeyboard($chat_id, $text, [
            [["text" => " 🔗 Sign Up", "url" => "https://youtube.com"]],
            [["text" => "📩 Re-Enter Email", "callback_data" => "re_auth"]],
        ]);
    }

    function sendInlineKeyboard($chat_id, $text, $buttons)
    {
        $keyboard = ["inline_keyboard" => $buttons];
        sendMessage($chat_id, $text, $keyboard);
    }

    function answerCallback($callback_id)
    {
        global $website;
        file_get_contents(
            $website .
                "/answerCallbackQuery?" .
                http_build_query([
                    "callback_query_id" => $callback_id,
                ])
        );
    }

    function saveSession($file, $data)
    {
        file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
    }

function sendVerificationEmail($to, $code)
{
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = EMAIL_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = EMAIL_USERNAME;
        $mail->Password = EMAIL_PASSWORD;
        $mail->SMTPSecure = EMAIL_SECURE;
        $mail->Port = EMAIL_PORT;

        $mail->setFrom(EMAIL_FROM, EMAIL_NAME);
        $mail->addAddress($to);
        $mail->Subject = "VHM Global Email Verification Code";
        $mail->Body = "Your email verification code is: $code";

        $mail->send();
        return true;
    } catch (Exception $e) {
        return false;
    }
}



?>
