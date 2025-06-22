<?php
/**
 * @file api/update_passport.php
 * @brief 사용자의 여권번호를 데이터베이스에 등록/수정하는 API
 * @description mypage.php의 여권 등록 모달에서 POST 요청을 받아,
 *              현재 로그인된 사용자의 CUSTOMER 테이블 레코드에 여권번호를 업데이트합니다.
 */

session_start();
header('Content-Type: application/json');

// --- 1. 접근 제어 및 입력 값 검증 ---
// 로그인이 되어 있는지 확인합니다.
if (!isset($_SESSION['cno'])) {
    echo json_encode(['success' => false, 'message' => '로그인이 필요합니다.']);
    exit;
}

// POST로 전송된 여권번호를 받습니다.
$passport_number = $_POST['passport_number'] ?? '';
// 여권번호가 비어있는지 확인합니다.
if (empty($passport_number)) {
    echo json_encode(['success' => false, 'message' => '여권번호를 입력해주세요.']);
    exit;
}

// --- 2. DB 연결 및 업데이트 처리 ---
require_once 'db_connect.php';
$conn = null;

try {
    $conn = new PDO($dsn, $db_username, $db_password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 현재 로그인된 사용자(CNO)의 PASSPORTNUMBER 필드를 업데이트하는 SQL 쿼리입니다.
    $sql = "UPDATE CUSTOMER SET PASSPORTNUMBER = :passport WHERE CNO = :cno";
    $stmt = $conn->prepare($sql);
    
    // PreparedStatement를 사용하여 안전하게 쿼리를 실행합니다.
    $stmt->execute([
        ':passport' => $passport_number,
        ':cno' => $_SESSION['cno']
    ]);

    // 성공적으로 업데이트 되었음을 클라이언트에 알립니다.
    // 업데이트된 여권번호를 함께 보내주어, 클라이언트가 페이지 새로고침 없이 화면을 갱신할 수 있도록 합니다.
    echo json_encode(['success' => true, 'message' => '여권번호가 성공적으로 등록되었습니다.', 'passport' => $passport_number]);

} catch (PDOException $e) {
    // DB 오류 발생 시, 실패 메시지를 클라이언트에 전달합니다.
    echo json_encode(['success' => false, 'message' => '데이터베이스 오류: ' . $e->getMessage()]);
} finally {
    // DB 연결을 종료합니다.
    $conn = null;
}
?>