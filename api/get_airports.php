<?php
/**
 * @file api/get_airports.php
 * @brief 데이터베이스에서 운항 중인 모든 공항 목록을 조회하는 API
 * @description main.php의 공항 선택 모달에 사용될 공항 목록을 JSON 형태로 제공합니다.
 */

// 클라이언트에게 반환될 콘텐츠 타입을 JSON으로 명시합니다.
header('Content-Type: application/json');

// DB 접속 정보가 담긴 파일을 포함합니다.
require_once 'db_connect.php';

$conn = null;
try {
    // PDO를 사용하여 데이터베이스에 접속합니다.
    $conn = new PDO($dsn, $db_username, $db_password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 핵심 로직: AIRPLANE 테이블의 출발/도착 공항을 모두 합쳐 중복 없는 목록을 생성합니다.
    // UNION은 자동으로 중복을 제거해주므로, 모든 공항 코드가 한 번씩만 포함됩니다.
    $sql = "(SELECT DISTINCT DEPARTUREAIRPORT AS AIRPORT_CODE FROM AIRPLANE)
            UNION
            (SELECT DISTINCT ARRIVALAIRPORT AS AIRPORT_CODE FROM AIRPLANE)
            ORDER BY AIRPORT_CODE ASC";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    
    // 조회 결과를 연관 배열 형태로 가져옵니다.
    $airports = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 성공적으로 조회된 공항 목록을 JSON으로 인코딩하여 응답합니다.
    echo json_encode(['success' => true, 'airports' => $airports]);

} catch (PDOException $e) {
    // 데이터베이스 작업 중 오류 발생 시, 에러 메시지를 포함한 JSON을 응답합니다.
    echo json_encode(['success' => false, 'message' => '데이터베이스 오류: ' . $e->getMessage()]);
} finally {
    // 스크립트 실행이 끝나면 데이터베이스 연결을 항상 종료합니다.
    $conn = null;
}
?>