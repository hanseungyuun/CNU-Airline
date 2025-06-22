<?php
/**
 * @file api/process_payment.php
 * @brief 항공권 예약 처리를 수행하고 결과를 반환하는 API
 * @description payment.php에서 받은 JSON 데이터를 기반으로 다음을 수행합니다:
 * 1. 데이터베이스 트랜잭션을 시작합니다.
 * 2. 예약 가능 여부(잔여 좌석 확인, 중복 예약 방지)를 검사합니다.
 * 3. RESERVE 테이블에 새로운 예약 정보를 추가합니다.
 * 4. PHPMailer를 사용하여 사용자에게 예약 확정 이메일을 발송합니다.
 * 5. 모든 과정이 성공하면 트랜잭션을 커밋하고, 실패 시 롤백합니다.
 */

// Composer로 설치한 PHPMailer 라이브러리를 로드합니다.
require dirname(__DIR__) . '/vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

session_start();
header('Content-Type: application/json');
require_once 'db_connect.php';

// --- 1. 입력 데이터 수신 및 검증 ---
// 클라이언트(JavaScript)에서 POST 방식으로 보낸 JSON 요청 본문을 읽어와 PHP 배열로 변환합니다.
$input = json_decode(file_get_contents('php://input'), true);

// 필수 데이터가 누락되었는지 확인합니다.
if (!$input || !isset($input['flightno'], $input['departureDateTime'], $input['seatClass'])) {
    echo json_encode(['success' => false, 'message' => '잘못된 요청 데이터입니다.']);
    exit;
}
// 로그인 상태 및 이메일 정보 존재 여부를 확인합니다.
if (!isset($_SESSION['cno'])) {
    echo json_encode(['success' => false, 'message' => '로그인이 필요합니다.']);
    exit;
}
if (!isset($_SESSION['email'])) {
    echo json_encode(['success' => false, 'message' => '사용자 이메일 정보가 없습니다. 다시 로그인해주세요.']);
    exit;
}

$conn = null;
try {
    // --- 2. 데이터베이스 연결 및 트랜잭션 시작 ---
    $conn = new PDO($dsn, $db_username, $db_password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 데이터의 일관성과 무결성을 보장하기 위해 트랜잭션을 시작합니다.
    // 이후 모든 DB 작업은 하나의 단위로 묶여, 전부 성공하거나 전부 실패(롤백)하게 됩니다.
    $conn->beginTransaction();

    // --- 3. 예약 가능 여부 확인 (동시성 제어) ---
    // 핵심 로직: `FOR UPDATE`를 사용하여 조회된 행에 잠금(lock)을 겁니다.
    // 이는 다른 사용자가 동시에 같은 항공편을 예약하려 할 때, 먼저 접근한 트랜잭션이
    // 끝날 때까지 기다리게 만들어 정확한 좌석 수를 보장하고 동시성 문제를 방지합니다.
    $sql_check = "SELECT s.NO_OF_SEATS - (SELECT COUNT(*) FROM RESERVE r WHERE r.FLIGHTNO = s.FLIGHTNO AND r.DEPARTUREDATETIME = s.DEPARTUREDATETIME AND r.SEATCLASS = s.SEATCLASS) AS REMAINING_SEATS
                  FROM SEATS s
                  WHERE s.FLIGHTNO = :flightno AND s.DEPARTUREDATETIME = TO_TIMESTAMP(:departureDateTime, 'YYYY-MM-DD\"T\"HH24:MI:SS') AND s.SEATCLASS = :seatClass
                  FOR UPDATE";
    $stmt_check = $conn->prepare($sql_check);
    $stmt_check->execute([
        ':flightno' => $input['flightno'],
        ':departureDateTime' => $input['departureDateTime'],
        ':seatClass' => $input['seatClass']
    ]);
    $result = $stmt_check->fetch(PDO::FETCH_ASSOC);

    // 잔여 좌석이 없으면 예외를 발생시켜 트랜잭션을 롤백합니다.
    if (!$result || $result['REMAINING_SEATS'] <= 0) {
        throw new Exception("예약 가능한 좌석이 없습니다. 다른 항공편을 선택해주세요.");
    }

    // --- 4. 중복 예약 방지 ---
    // 동일한 사용자가 같은 항공편을 이미 예약했는지 확인합니다.
    $sql_duplicate = "SELECT COUNT(*) FROM RESERVE WHERE CNO = :cno AND FLIGHTNO = :flightno AND DEPARTUREDATETIME = TO_TIMESTAMP(:departureDateTime, 'YYYY-MM-DD\"T\"HH24:MI:SS') AND SEATCLASS = :seatClass";
    $stmt_duplicate = $conn->prepare($sql_duplicate);
    $stmt_duplicate->execute([
        ':cno' => $_SESSION['cno'],
        ':flightno' => $input['flightno'],
        ':departureDateTime' => $input['departureDateTime'],
        ':seatClass' => $input['seatClass']
    ]);
    if ($stmt_duplicate->fetchColumn() > 0) {
        throw new Exception("이미 예약된 항공편입니다. 마이페이지에서 확인해주세요.");
    }

    // --- 5. 예약 정보 삽입 ---
    // 모든 검사를 통과했다면, RESERVE 테이블에 새로운 예약 정보를 INSERT 합니다.
    $sql_insert = "INSERT INTO RESERVE (FLIGHTNO, DEPARTUREDATETIME, SEATCLASS, PAYMENT, RESERVEDATETIME, CNO)
                   VALUES (:flightno, TO_TIMESTAMP(:departureDateTime, 'YYYY-MM-DD\"T\"HH24:MI:SS'), :seatClass, :payment, SYSTIMESTAMP, :cno)";
    $stmt_insert = $conn->prepare($sql_insert);
    $stmt_insert->execute([
        ':flightno' => $input['flightno'],
        ':departureDateTime' => $input['departureDateTime'],
        ':seatClass' => $input['seatClass'],
        ':payment' => $input['payment'],
        ':cno' => $_SESSION['cno']
    ]);

    // --- 6. 예약 확정 이메일 발송 ---
    // DB 작업이 성공적으로 수행된 후에 이메일을 발송합니다.
    send_booking_confirmation($_SESSION['email'], $input);

    // --- 7. 트랜잭션 커밋 ---
    // 모든 작업(DB INSERT, 이메일 발송)이 성공적으로 완료되었으므로,
    // 트랜잭션의 모든 변경사항을 데이터베이스에 영구적으로 반영합니다.
    $conn->commit();

    // 클라이언트에게 성공 메시지와 함께 필요한 정보를 반환합니다.
    echo json_encode(['success' => true, 'message' => '예약이 완료되었습니다.', 'email' => $_SESSION['email'], 'payment' => $input['payment']]);

} catch (Exception $e) {
    // --- 8. 오류 처리 및 트랜잭션 롤백 ---
    // try 블록 내에서 예외가 발생하면(좌석 부족, DB 오류, 이메일 발송 실패 등),
    // 현재 진행 중인 트랜잭션이 있다면 모든 변경사항을 취소(롤백)합니다.
    if ($conn && $conn->inTransaction()) {
        $conn->rollBack();
    }
    // 클라이언트에게 실패 상태와 구체적인 오류 메시지를 반환합니다.
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);

} finally {
    // 스크립트 실행이 끝나면 데이터베이스 연결을 항상 종료합니다.
    $conn = null;
}

/**
 * @function send_booking_confirmation
 * @brief 예약 확정 이메일을 발송합니다.
 * @param string $to_email 수신자 이메일 주소
 * @param array $flightData 예약 정보가 담긴 배열
 * @throws Exception PHPMailer에서 이메일 발송 실패 시 예외를 발생시킵니다.
 */
function send_booking_confirmation($to_email, $flightData) {
    $mail = new PHPMailer(true);
    try {
        // --- SMTP 서버 설정 (Gmail 기준) ---
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = '231syhan@gmail.com'; // 발신자 Gmail 계정
        $mail->Password   = 'wvyn kmcv taks jwvt';      // Gmail 앱 비밀번호
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port       = 465;
        $mail->CharSet    = 'UTF-8'; // 한글 깨짐 방지

        // --- 수신자/발신자 정보 ---
        $mail->setFrom('CNU.airline@gmail.com', 'CNU AIRLINE'); // 보내는 사람 정보
        $mail->addAddress($to_email); // 받는 사람 정보

        // --- 이메일 내용 ---
        $mail->isHTML(true);
        $mail->Subject = '[CNU AIRLINE] 항공권 예약 확정 안내';
        $departureDateTimeFormatted = (new DateTime($flightData['departureDateTime']))->format('Y년 m월 d일 H:i');
        $paymentFormatted = number_format($flightData['payment']);

        // HTML 형식의 이메일 본문 작성
        $mail->Body = "
            <div style='font-family: sans-serif; max-width: 600px; margin: auto; border: 1px solid #ddd;'>
                <div style='background-color: #0064d2; color: white; padding: 20px; text-align: center;'>
                    <h1>예약이 완료되었습니다!</h1>
                </div>
                <div style='padding: 20px;'>
                    <p>항상 CNU AIRLINE을 이용해주셔서 감사합니다.</p>
                    <hr>
                    <p><strong>항공편:</strong> {$flightData['flightno']}</p>
                    <p><strong>출발일시:</strong> {$departureDateTimeFormatted}</p>
                    <p><strong>좌석:</strong> {$flightData['seatClass']}</p>
                    <p><strong>결제금액:</strong> <strong>{$paymentFormatted} 원</strong></p>
                    <hr>
                    <p style='font-size: 0.9em; color: #777;'>본 메일은 발신 전용입니다.</p>
                </div>
            </div>";

        $mail->send();
    } catch (Exception $e) {
        // 이메일 발송에 실패하면 예외를 던집니다.
        // 이 예외는 상위의 catch 블록에서 감지되어, 전체 트랜잭션을 롤백하게 만듭니다.
        // (즉, 이메일 발송 실패 시 DB 예약도 취소됨)
        // error_log("Mailer Error: " . $mail->ErrorInfo); // 실제 서버에서는 에러 로그를 남기는 것이 좋음
        throw new Exception("예약 처리 중 이메일 발송에 실패하여 모든 작업을 취소했습니다.");
    }
}
?>