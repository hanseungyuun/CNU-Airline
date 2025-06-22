<?php
/**
 * @file api/cancel_reservation.php
 * @brief 예약 취소를 처리하고 결과를 반환하는 API
 * @description reserve_history.php에서 POST 요청을 받아 다음을 수행합니다:
 * 1. 데이터베이스 트랜잭션을 시작합니다.
 * 2. 취소하려는 예약이 유효한지, 이미 취소된 것은 아닌지 검사합니다.
 * 3. 서버의 위약금 정책에 따라 환불액을 계산합니다.
 * 4. CANCEL 테이블에 취소 정보를 기록합니다. (RESERVE 테이블의 데이터는 삭제하지 않음)
 * 5. 모든 과정이 성공하면 트랜잭션을 커밋하고, 실패 시 롤백합니다.
 */

session_start();
header('Content-Type: application/json');
require_once 'db_connect.php';

// --- 1. 접근 제어 및 입력 값 검증 ---
if (!isset($_SESSION['cno'])) {
    echo json_encode(['success' => false, 'message' => '로그인이 필요합니다.']);
    exit;
}
$input = json_decode(file_get_contents('php://input'), true);
if (!isset($input['flightno'], $input['departureDateTime'], $input['seatClass'])) {
    echo json_encode(['success' => false, 'message' => '취소할 항공편 정보가 올바르지 않습니다.']);
    exit;
}

$conn = null;
try {
    // --- 2. 데이터베이스 연결 및 트랜잭션 시작 ---
    $conn = new PDO($dsn, $db_username, $db_password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->beginTransaction();

    // --- 3. 취소 대상 예약 검증 ---
    // 3-1. RESERVE 테이블에 해당 예약이 존재하는지 확인합니다.
    $sql_fetch = "SELECT PAYMENT, TO_CHAR(DEPARTUREDATETIME, 'YYYY-MM-DD\"T\"HH24:MI:SS') AS DEPARTUREDATETIME
                  FROM RESERVE
                  WHERE CNO = :cno AND FLIGHTNO = :flightno
                    AND DEPARTUREDATETIME = TO_TIMESTAMP(:departureDateTime, 'YYYY-MM-DD\"T\"HH24:MI:SS')
                    AND SEATCLASS = :seatClass";
    $stmt_fetch = $conn->prepare($sql_fetch);
    $stmt_fetch->execute([
        ':cno' => $_SESSION['cno'],
        ':flightno' => $input['flightno'],
        ':departureDateTime' => $input['departureDateTime'],
        ':seatClass' => $input['seatClass']
    ]);
    $reservation = $stmt_fetch->fetch(PDO::FETCH_ASSOC);
    if (!$reservation) {
        throw new Exception("취소할 예약 내역을 찾을 수 없습니다.");
    }

    // 3-2. CANCEL 테이블을 확인하여 이미 취소된 예약인지 검사합니다. (중복 취소 방지)
    $sql_check_cancel = "SELECT COUNT(*) FROM CANCEL
                         WHERE CNO = :cno AND FLIGHTNO = :flightno
                           AND DEPARTUREDATETIME = TO_TIMESTAMP(:departureDateTime, 'YYYY-MM-DD\"T\"HH24:MI:SS')
                           AND SEATCLASS = :seatClass";
    $stmt_check_cancel = $conn->prepare($sql_check_cancel);
    $stmt_check_cancel->execute([
        ':cno' => $_SESSION['cno'],
        ':flightno' => $input['flightno'],
        ':departureDateTime' => $input['departureDateTime'],
        ':seatClass' => $input['seatClass']
    ]);
    if ($stmt_check_cancel->fetchColumn() > 0) {
        throw new Exception("이미 취소된 예약입니다.");
    }

    // --- 4. 위약금 및 환불액 계산 ---
    $departure_dt = new DateTime($reservation['DEPARTUREDATETIME']);
    $now_dt = new DateTime();
    // 정확한 날짜 차이를 계산하기 위해 시간, 분, 초는 0으로 초기화합니다.
    $departure_dt->setTime(0, 0, 0);
    $now_dt->setTime(0, 0, 0);

    $interval = $now_dt->diff($departure_dt);
    $days_diff = (int) $interval->format('%r%a'); // 남은 일수 (+는 미래, -는 과거)

    if ($days_diff < 0) {
        throw new Exception("출발일이 지난 항공편은 취소할 수 없습니다.");
    }

    $payment = (float) $reservation['PAYMENT'];
    $penalty = 0;

    // 위약금 정책 (서버 사이드에서 최종 결정)
    if ($days_diff == 0) { // 당일 취소
        $penalty = $payment; // 전액 위약금
    } elseif ($days_diff <= 3) { // 1~3일 전
        $penalty = 250000;
    } elseif ($days_diff <= 14) { // 4~14일 전
        $penalty = 180000;
    } else { // 15일 이전
        $penalty = 150000;
    }

    $refund = $payment - $penalty;
    if ($refund < 0) $refund = 0; // 환불액이 음수가 될 수 없음

    // --- 5. CANCEL 테이블에 취소 정보 삽입 ---
    // 취소 정보를 CANCEL 테이블에 기록합니다. 이 데이터는 '취소 내역' 페이지에서 사용됩니다.
    $sql_insert = "INSERT INTO CANCEL (FLIGHTNO, DEPARTUREDATETIME, SEATCLASS, REFUND, CANCELDATETIME, CNO)
                   VALUES (:flightno, TO_TIMESTAMP(:departureDateTime, 'YYYY-MM-DD\"T\"HH24:MI:SS'), :seatClass, :refund, SYSTIMESTAMP, :cno)";
    $stmt_insert = $conn->prepare($sql_insert);
    $stmt_insert->execute([
        ':flightno' => $input['flightno'],
        ':departureDateTime' => $input['departureDateTime'],
        ':seatClass' => $input['seatClass'],
        ':refund' => $refund,
        ':cno' => $_SESSION['cno']
    ]);

    // --- 6. 트랜잭션 커밋 ---
    // 모든 DB 작업이 성공적으로 완료되었으므로 변경사항을 영구 저장합니다.
    $conn->commit();
    echo json_encode(['success' => true, 'message' => '예약이 성공적으로 취소되었습니다.']);

} catch (Exception $e) {
    // 예외 발생 시, 트랜잭션의 모든 변경사항을 취소(롤백)합니다.
    if ($conn && $conn->inTransaction()) {
        $conn->rollBack();
    }
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} finally {
    // DB 연결을 종료합니다.
    $conn = null;
}
?>