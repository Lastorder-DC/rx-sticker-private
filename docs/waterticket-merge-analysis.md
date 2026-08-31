# Waterticket 스티커 모듈 변경사항 통합 분석

## 1. 문서 목적

이 문서는 다음 세 저장소의 관계와 차이를 정리하고, Waterticket 저장소의 개선사항을 현재 비공개 저장소에 안전하게 통합하기 위한 기준과 작업 순서를 정의한다.

- 현재 비공개 저장소: `Lastorder-DC/rx-sticker-private`
- 공개 기준 저장소: [fanbinit/rx-sticker](https://github.com/fanbinit/rx-sticker)
- 통합 대상 저장소: [Waterticket/rx-module-sticker](https://github.com/Waterticket/rx-module-sticker)

이 문서는 2026년 8월 17일을 기준으로 작성했다. 비교 기준 리비전은 다음과 같다.

| 저장소 | 기준 브랜치 및 리비전 | 비고 |
| --- | --- | --- |
| 현재 비공개 저장소 | `main` / `2c8b978` | 분석 시점의 로컬 및 `origin/main` |
| fanbinit | `master` / [`89ff36b`](https://github.com/fanbinit/rx-sticker/commit/89ff36b) | 공개 저장소 최신 리비전 |
| Waterticket | `main` / [`ffbff35`](https://github.com/Waterticket/rx-module-sticker/commit/ffbff35) | 버전 1.1.1 |

## 2. 결론

Waterticket 저장소를 현재 비공개 저장소에 직접 병합하거나 전체 파일을 덮어쓰는 방식은 안전하지 않다.

현재 저장소는 Git 이력상 두 공개 저장소와 공통 조상이 없지만, 최초 리비전의 파일 내용은 fanbinit 저장소를 바탕으로 수동 재구성한 것으로 추정된다. 이후 현재 저장소에는 개인 스티커 차단, 쿼리 최적화, 캐시, WebP, 모바일 및 Hios 스킨 등 독자적인 개선이 추가되었다.

Waterticket 저장소에는 PHP 8 안정성 수정, 라우팅, 이벤트 핸들러, Blade 스킨, GIF의 MP4 변환과 Queue 처리 등 도입 가치가 있는 변경이 있다. 그러나 이를 그대로 가져오면 현재 저장소의 일부 기능이 삭제되거나 성능과 호환성이 퇴행한다.

따라서 다음 원칙으로 통합한다.

1. Waterticket 커밋을 통째로 병합하거나 cherry-pick하지 않는다.
2. 변경사항을 기능 단위로 검토하여 현재 코드 위에 재구현한다.
3. 현재 저장소의 개인 차단, WebP, 쿼리 최적화, 캐시와 모바일 기능을 보존하고 반응형 modern Blade 스킨을 추가하되, 기존 스킨 선택 기능도 유지한다.
4. 각 단계는 독립된 커밋으로 작성하고 단계별 회귀 테스트를 수행한다.
5. Waterticket 코드를 실질적으로 사용한 경우 원본 커밋과 저작권 정보를 기록한다.

## 3. 저장소 계보와 비교 방법

### 3.1 Git 이력

fanbinit과 Waterticket 저장소는 `9a529fc`까지 공통 이력을 가진다. 그 이후 양쪽 저장소가 각각 별도로 발전했다.

현재 비공개 저장소는 두 공개 저장소와 공통 Git 조상이 없다. 따라서 일반적인 3-way merge가 실제 코드 계보를 올바르게 해석할 수 없다.

### 3.2 내용 기준 비교

개행, 공백, 실행 권한 및 vendor 변경으로 인한 잡음을 제외하고 파일 내용을 비교했다.

| 비교 대상 | 실질적으로 다른 파일 | 주요 의미 |
| --- | ---: | --- |
| 현재 저장소 ↔ fanbinit | 약 69개 | 현재 저장소의 독자 기능과 리팩터링 |
| 현재 저장소 ↔ Waterticket | 약 87개 | 양쪽 저장소가 서로 다른 방향으로 발전 |
| fanbinit ↔ Waterticket | 약 57개 | 공통 조상 이후 Waterticket의 변경 범위 |

현재 저장소는 340개, fanbinit은 314개, Waterticket은 303개의 추적 파일을 가지고 있다. 파일 수만으로 기능의 우열을 판단할 수는 없지만, Waterticket 버전이 모바일 및 Hios 스킨을 제거하고 Blade 기반 기본 스킨으로 구조를 단순화했다는 점을 보여준다.

### 3.3 병합 시뮬레이션

임시 저장소에서 현재 저장소의 최초 리비전을 fanbinit 계보에 연결한 뒤 Waterticket 최신 리비전을 병합하는 시뮬레이션을 수행했다. README, `conf/info.xml`, `conf/module.xml`, 언어 파일, 스킨 리소스, 주요 PHP 클래스 등 21개 파일에서 명시적인 충돌이 발생했다.

자동 병합되는 파일에도 의미상 충돌이 남는다. 예를 들어 Waterticket의 과거 쿼리와 이미지 조회 방식을 자동 적용하면 현재 저장소의 JOIN 및 캐시 최적화가 퇴행할 수 있다. 따라서 충돌 파일 수만 해결하는 방식으로는 안전성을 확보할 수 없다.

## 4. 현재 비공개 저장소의 독자 기능

다음 기능은 Waterticket 전체 덮어쓰기 시 손실되거나 퇴행할 수 있으므로 반드시 보존한다.

- 개인 스티커 차단 기능
  - 차단 등록 및 해제 액션
  - 개인 차단 목록 화면
  - 관련 스키마와 쿼리
  - 스킨의 차단 UI
- `lang/ko.php`, `lang/en.php` 기반 언어 파일
- WebP 업로드 및 이미지 처리
- 대표 이미지 JOIN 쿼리
- 스티커 항목 캐시
- 최적화된 내 스티커 목록 쿼리
- 최신 `Rhymix.ajax` 호출 방식
- 스티커 파일과 `sticker_srl` 복구 로직
- 현재 업로더 구현
- 클릭 오버레이 동작
- 모바일 화면의 기능
- 기존 default/Hios 설정값과의 호환

특히 `queries/getStickerMylist.xml`과 스티커 조회 계열 쿼리를 Waterticket 버전으로 교체하지 않는다. 대표 이미지 개별 조회가 다시 도입되면 N+1 쿼리 문제가 발생할 가능성이 있다.

## 5. Waterticket 변경사항 분류

### 5.1 우선 선별 적용할 변경

#### PHP 8 및 런타임 안정성

Waterticket의 [`b5b6b40`](https://github.com/Waterticket/rx-module-sticker/commit/b5b6b40515527ff439519bbbe736e9d5dbcd1c48)에는 다음과 같은 유효한 방어 코드가 포함되어 있다.

- 빈 요청값과 누락된 객체 속성 검사
- 탈퇴 회원과 삭제된 스티커의 관리자 화면 표시 보완
- 세션 배열 접근 전 존재 여부 검사
- 빈 쿼리 결과를 안전하게 처리
- 배열 반환 쿼리에 `executeQueryArray()` 사용
- 파일 핸들이 리소스인지 확인한 후 `fclose()` 실행

단, 이 커밋에는 현재 저장소보다 오래된 대표 이미지 조회 코드 등도 포함되어 있으므로 커밋 전체를 적용하지 않고 필요한 변경만 다시 작성한다.

현재 저장소에서 별도로 확인된 우선 수정 대상은 다음과 같다.

- `models/Sticker.php`의 `page > 1`은 `$page > 1`로 수정해야 한다. PHP 8에서 정의되지 않은 상수 접근은 오류가 될 수 있다.
- `controllers/Admin.php`의 `procStickerAdminDesign()`은 정의되지 않은 `$config`를 `updateModuleConfig()`에 전달한다. 해당 호출은 제거하거나 실제 설정 객체를 구성해야 한다.

#### 라우팅

Waterticket의 `dispStickerList`, `dispStickerWrite`, `dispStickerDelete`, `dispStickerMylist` route 선언은 도입 가치가 있다. 현재 저장소의 개인 차단 화면을 위한 `dispStickerMyBlock` route도 함께 정의해야 한다.

route 선언은 Rhymix의 rewrite 설정과 함께 검증한다. 자세한 동작은 [Rhymix 라우터 매뉴얼](https://rhymix.org/manual/plugin/router/router)을 기준으로 한다.

#### 이벤트 핸들러

Waterticket은 수동 트리거 등록을 `conf/module.xml`의 `eventHandlers`로 이전했다. 이 방식은 Rhymix 2.1 계열에서 권장되는 구조다. 관련 내용은 [Rhymix 2.1.3 변경 안내](https://rhymix.org/news/373)를 참고한다.

통합 시 기존 수동 트리거를 일부만 옮기지 않는다. 현재 모듈이 사용하는 전체 이벤트를 한 번에 `module.xml`에 선언하고 설치·업데이트·제거 로직을 함께 정리해야 한다.

알림센터의 `ncenterlite._insertNotify` 이전 이벤트에서 스티커 토큰을 `[스티커]` 같은 요약으로 바꾸는 기능도 유용하다. 다만 표시 문자열은 하드코딩하지 않고 언어 파일 또는 설정값으로 제공한다.

### 5.2 수정 후 적용할 변경

#### GIF에서 MP4로 변환

Waterticket의 다음 변경은 기능 방향은 유효하지만 현재 코드에 맞춘 재설계가 필요하다.

- `services/ImageProcessor.php` 도입
- FFmpeg 기반 애니메이션 GIF의 MP4 변환
- Queue 기반 비동기 처리
- 기존 GIF 일괄 변환
- MP4 포스터용 WebP 생성

도입 전에 다음 문제를 해결한다.

1. Waterticket은 PHP 7.4 이상을 요구한다고 문서화했지만 여러 파일에서 `str_ends_with()`를 사용한다. 이 함수는 [PHP 8.0 이상](https://www.php.net/manual/en/function.str-ends-with.php)에서만 지원된다. PHP 7.4 호환 헬퍼 또는 `substr()` 기반 검사로 교체한다.
2. Waterticket의 새 이미지 검증 목록에는 WebP가 없다. 현재 WebP 지원을 유지하도록 허용 확장자와 MIME 검사를 통합한다.
3. 생성된 WebP 포스터 파일이 파일 테이블에 등록되지 않으면 스티커 삭제 후 고아 파일로 남는다. `files.thumbnail_filename`에 포스터 경로를 저장하거나 별도 삭제 이벤트를 제공한다. Rhymix의 파일 삭제 동작은 [file.controller.php](https://github.com/rhymix/rhymix/blob/b3c3ed5eac5d53c35df4196437b81bf95ca75038/modules/file/file.controller.php#L1683-L1755)를 기준으로 검증한다.
4. Queue 작업은 중복 실행, 이미 삭제된 파일, 이미 변환된 파일을 안전하게 처리할 수 있어야 한다.
5. 출력 파일은 임시 경로에 완전히 생성한 후 원자적으로 교체한다. DB 업데이트 실패 시 생성 파일을 제거하고 기존 원본을 유지한다.
6. 대량 변환은 한 HTTP 요청에서 무제한 작업을 등록하지 않고 페이지 또는 배치 크기를 제한한다.
7. FFmpeg 미설치, 실행 실패, 지원하지 않는 코덱, Queue driver 미설정 상태를 관리자에게 명확히 표시한다.
8. 관리자 액션은 명시적으로 최고 관리자 권한을 요구하고 CSRF 보호를 적용한다.
9. 관리자 메시지와 확인 문구는 `lang/ko.php`, `lang/en.php`에 추가한다.

Queue 사용 방법은 현재 Rhymix 코어의 [Queue.php](https://github.com/rhymix/rhymix/blob/b3c3ed5eac5d53c35df4196437b81bf95ca75038/common/framework/Queue.php#L112-L151)를 기준으로 구현한다.

#### Blade 스킨

Waterticket의 Blade 스킨은 `skins/modern`으로 이식했다. 구현 중 레거시 템플릿 엔진이 `<video loop>`를 반복문 지시자로 오인하여 `Unmatched '}'` 오류를 만드는 문제가 실제로 확인되어 모든 레거시 스킨의 해당 구문도 수정했다. 기존 설치의 최초 모듈 업데이트 시 modern을 기본 선택으로 적용하되, 1회성 마이그레이션 표식을 저장하여 이후 사용자가 선택한 스킨을 다시 덮어쓰지 않는다. 관리자 디자인 설정과 스킨 설정 화면은 동일한 DB 값을 참조한다.

같은 이름의 `.html`과 `.blade.php`가 함께 있으면 Rhymix는 `.html`을 먼저 선택한다. 단순히 Blade 파일을 기존 스킨에 추가해서는 전환되지 않는다. 관련 로딩 규칙은 Rhymix 코어의 [Template.php](https://github.com/rhymix/rhymix/blob/b3c3ed5eac5d53c35df4196437b81bf95ca75038/common/framework/Template.php#L149-L168)를 기준으로 한다.

통합 Blade 스킨에는 다음 기능을 반영했다.

- 개인 차단 화면과 버튼
- WebP 및 MP4 렌더링
- 현재 업로더 기능
- 다국어 문자열
- URL과 HTML 속성의 안전한 출력
- 데스크톱·모바일 반응형 화면과 기존 스킨의 주요 기능
- 기존 스킨과 호환되는 타이틀·설명·샘플 크기·제목 길이·제작자 표시 설정
- 제목 하단 설명의 HTML 및 Rhymix 위젯 코드 실행
- 관리자 전용 `CHECK` → `PUBLIC` 즉시 승인 버튼과 회원번호 4 전용 IP 표시
- 레이아웃 색상 변수를 따르는 다크모드 제목과 태그 위젯, 원본 색을 보존하는 밝은 상세 본문

Waterticket 기본 스킨의 `LICENSE`가 GPLv2이므로 해당 코드를 사용한다면 스킨의 라이선스와 저작자 정보를 유지한다. 모듈 최상위 라이선스와 스킨 라이선스를 혼동하지 않는다.

### 5.3 적용하지 않을 변경

다음 변경은 현재 저장소에 그대로 적용하지 않는다.

- Waterticket 쿼리 전체 덮어쓰기
- `lang/lang.xml` 복원
- 개인 차단과 모바일 기능을 제거하는 방식의 스킨 교체
- WebP 지원 제거
- 현재 캐시와 대표 이미지 JOIN 제거
- Waterticket 버전 `1.1.1`을 현재 모듈 버전으로 그대로 사용
- 메서드 인자의 참조 기호 `&`를 기계적으로 일괄 제거
- PHP 7.4 환경에 `str_ends_with()`를 그대로 도입
- 한국어 하드코딩 UI 및 메시지
- 대량 변환 관리자 액션에 암묵적인 관리자 권한만 의존하는 구현

## 6. 설정, 권한 및 다국어 원칙

현재 `conf/module.xml`에는 `<permissions />`가 비어 있고 route와 event handler 선언이 없다. 통합 과정에서 다음을 명시한다.

- 목록, 열람, 등록, 구매, 무료 구매의 grant
- 개인 차단 등록 및 해제에 필요한 로그인 권한
- 일괄 변환 및 관리자 변경 액션의 최고 관리자 권한
- 공개 view와 변경 controller 액션의 구분
- 모든 공개 route
- 모듈이 사용하는 전체 event handler

새로운 사용자 및 관리자 문자열은 `lang/ko.php`와 `lang/en.php`에 함께 추가한다. Blade 템플릿, PHP 컨트롤러, JavaScript에 사용자용 문구를 직접 하드코딩하지 않는다.

## 7. 권장 통합 단계

### 단계 1: 기존 결함과 PHP 8 안정성 수정

- `$page` 오타 수정
- 정의되지 않은 `$config` 사용 제거
- 빈 결과, 탈퇴 회원, 삭제된 스티커 방어
- 세션 배열 접근 방어
- 파일 리소스 종료 검사
- PHP 8.4에서 문법 및 주요 관리자 기능 확인

### 단계 2: route, 권한 및 이벤트 선언

- 현재 액션을 기준으로 권한 매핑 작성
- Waterticket route를 현재 기능에 맞춰 이식
- `dispStickerMyBlock` route 추가
- 수동 트리거를 `eventHandlers`로 원자적으로 이전
- 설치, 업데이트, 제거 과정에서 중복 이벤트가 생기지 않는지 확인

### 단계 3: 알림센터 연동

- `ncenterlite._insertNotify` 이벤트 핸들러 추가
- 스티커 토큰을 알림용 요약 문자열로 변환
- 알림센터 모듈이 없는 환경에서도 오류가 발생하지 않도록 처리
- 한국어 및 영어 문자열 제공

### 단계 4: 통합 현대화 스킨

- Waterticket Blade 스킨을 `skins/modern`으로 이식
- 개인 차단, WebP, 현재 업로더 기능 병합
- 하드코딩 문자열 외부화
- 모듈 업데이트 시 modern/반응형을 기본 선택으로 적용하고 기존 스킨 선택 기능 유지

### 단계 5: 이미지 처리 서비스 분리

- 현재 `_insertImage()` 및 `_updateImage()` 동작을 서비스로 이동
- JPEG, PNG, GIF, WebP 동작 보존
- 크기 제한과 MIME 검증 유지
- 서비스 도입 전후 결과가 같은지 회귀 테스트

### 단계 6: 선택형 GIF→MP4 변환

- FFmpeg 기능 검사
- Queue on/off 모두 지원
- MP4와 포스터 파일 수명주기 구현
- 신규 업로드와 수정 업로드 지원
- 관리자용 기존 GIF 배치 변환 제공
- 초기 기본값은 비활성화

### 단계 7: 단계적 배포

- DB 및 `files/` 백업
- 일부 스티커로 canary 변환
- Queue 오류와 파일 누락 감시
- 이상이 없을 때 배치 크기를 점진적으로 확대
- 문제 발생 시 설정 비활성화와 원본 복구가 가능하도록 유지

## 7.1 구현 현황

2026년 8월 17일 현재 단계 1~6의 코드 통합을 완료했다.

- PHP 8 오류와 빈 결과·리소스 처리 보완
- route, 액션 권한 및 전체 이벤트 핸들러 선언
- 다국어 알림센터 요약 연동
- 개인 차단, WebP, MP4와 현재 업로더를 포함한 반응형 Blade 스킨 추가 및 기본 선택
- JPEG, PNG, GIF, WebP 이미지와 직접 업로드 MP4 처리 서비스 분리
- 직접 업로드 MP4의 실제 컨테이너·영상 스트림 검증, 강제 무음 H.264 재인코딩, 30fps·스티커 크기 최적화 및 WebP 포스터 생성
- 기본 비활성 상태의 GIF→MP4 변환과 최대 100개 단위 Queue 배치 기능
- MP4 WebP 포스터를 `files.thumbnail_filename`에 연결하여 파일 삭제 수명주기에 포함
- 파일별 GIF 변환 상태와 결과 사유를 보존하는 관리자 로그 및 개별 재시도 기능
- 로컬 원본이 없는 R2 전용 레코드는 `SKIPPED/source_missing`으로 기록하고 다음 대상으로 진행
- 페이지당 스티커 수 설정(기본 12개) 및 200px 샘플 기준 6열 × 2행 확인
- 선택한 스킨과 스킨 설정 대상의 일치, modern 표시 설정과 관리자 공개 승인 기능
- 네임스페이스 이벤트 핸들러의 `ModuleObject` 호환 및 댓글 알림 트리거 실호출 확인

단계 7은 운영 데이터와 실제 Queue·FFmpeg 환경이 필요한 배포 절차이므로 코드에서 자동 실행하지 않는다. 관리자 모듈 업데이트 후 백업, canary 변환, 모니터링 순서로 진행해야 한다.

## 8. 테스트 매트릭스

### 8.1 PHP 및 Rhymix

- PHP 7.4
- 프로젝트의 운영 PHP 버전
- PHP 8.4 이상
- Rhymix 2.1 최신 안정 버전

### 8.2 이미지 형식

- JPEG
- PNG
- 정적 GIF
- 애니메이션 GIF
- WebP
- 확장자와 MIME이 일치하지 않는 파일
- 최소 크기 미만 이미지
- 최대 해상도 초과 이미지

### 8.3 변환 환경

- FFmpeg 설치 및 정상 동작
- FFmpeg 미설치
- Queue 비활성화
- Queue 동기 driver
- Queue 비동기 driver
- 동일 작업 중복 등록
- 작업 실행 전 원본 삭제
- MP4 생성 성공 후 DB 업데이트 실패

### 8.4 파일 수명주기

- 신규 업로드
- 기존 스티커 수정
- 개별 이미지 삭제
- 스티커 묶음 삭제
- 모듈 데이터 삭제
- MP4와 WebP 포스터 동시 삭제
- 실패한 변환의 임시 파일 정리

### 8.5 화면과 권한

- 비로그인 사용자
- 일반 회원
- 스티커 등록 권한 보유 회원
- 모듈 관리자
- 최고 관리자
- 기존 default 설정값의 modern Blade 전환
- 기존 Hios 설정값의 modern Blade 전환
- 모바일 default 설정값의 반응형 modern Blade 전환
- 개인 차단 등록 및 해제
- 알림센터 설치 및 미설치 환경
- rewrite 설정 0, 1, 2

## 9. 커밋 및 출처 관리

각 통합 단계는 별도 커밋으로 유지한다. 커밋 메시지 본문에는 참고한 Waterticket 커밋을 기록한다.

예시:

```text
Fix PHP 8 errors in sticker admin and model

Adapted selected defensive checks from:
https://github.com/Waterticket/rx-module-sticker/commit/b5b6b40515527ff439519bbbe736e9d5dbcd1c48
```

Blade 스킨 등 상당한 코드를 가져오는 경우 해당 디렉터리의 라이선스와 저작자 표기를 유지한다. 현재 저장소의 독자 버전을 부여하고 Waterticket의 `1.1.1`을 그대로 사용하지 않는다.

## 10. 검증 현황과 한계

분석 및 구현 단계에서 수행한 검증은 다음과 같다.

- 현재 저장소 작업 트리 상태 확인
- 세 저장소의 Git 이력 및 파일 내용 비교
- 임시 저장소 병합 시뮬레이션
- 현재 저장소와 Waterticket PHP 파일의 PHP 8.4 문법 검사
- JavaScript 문법 검사
- Git whitespace 검사
- PHP 7.4에서 지원되지 않는 함수의 정적 검색
- 저장소 전체 PHP 파일 문법 검사
- 전체 XML 설정·쿼리·스키마 파싱
- 전체 JavaScript 파일 문법 검사
- modern Blade 9개와 레거시·관리자 템플릿 37개를 Rhymix 파서로 변환한 결과의 PHP 문법 검사
- 레거시 템플릿 오류 원인 재현 및 모든 해당 `<video loop>` 구문 수정
- 로컬 웹서버에서 `/sticker` 목록과 실제 스티커 상세 route의 HTTP 200 및 Blade 출력 확인
- 운영 설정에서 FFmpeg·Queue 활성 상태 확인 및 `/tmp` GIF 복사본의 MP4·WebP 포스터 생성/정리 확인
- 오디오 포함 MP4 직접 업로드 복사본을 처리하여 H.264 단일 영상 스트림, 30fps, 설정 크기 및 WebP 포스터 확인
- 실제 `ModuleActionParser`를 통한 공개 승인 액션의 POST·manager 권한·ruleset·CSRF 메타데이터 확인
- 실제 `ncenterlite._insertNotify` 이벤트 호출을 통한 namespaced 핸들러 호환성과 알림 문구 변환 확인
- 운영 DB의 modern 스킨, 반응형 모바일 스킨, 페이지당 12개 설정 및 모듈 업데이트 완료 상태 확인

### 10.1 Rhymix 모듈 표준 감사

- namespaced 이벤트 핸들러는 `Controllers\Base`를 거쳐 `ModuleObject`를 상속한다.
- 공개 승인 액션은 `conf/module.xml`에 권한과 ruleset을 선언하고, 컨트롤러에서도 권한·현재 상태를 재검증한다.
- 저장 IP는 `RX_CLIENT_IP`로 수집하며, 회원번호 4 이외의 요청에서는 템플릿 출력뿐 아니라 View Context에서도 제거한다.
- 관리자 IP 검색도 동일한 권한 조건을 적용하여 URL 파라미터를 통한 우회를 막는다.
- 이미지 변환은 모듈 내부 서비스와 Rhymix DB·File·Queue API만 사용하며 코어 파일은 수정하지 않는다.
- 새로 추가한 코드는 PHP 7.4 문법 범위와 탭 들여쓰기, 다국어 문자열 원칙을 따른다.

기존 XE 시절 파일에는 메서드 가시성·PHPDoc 누락, 느슨한 비교, 혼합 줄바꿈 등 레거시 스타일이 남아 있다. 기능 통합과 무관한 대규모 정규화는 회귀 위험과 diff 노이즈가 커서 이번 작업에는 포함하지 않고 별도 리팩터링 대상으로 둔다.

아직 수행하지 않은 검증은 다음과 같다.

- Queue driver별 동작
- 파일 삭제와 롤백 통합 테스트
- 지원 브라우저와 모든 선택형 스킨의 수동 UI 회귀 테스트

따라서 코드와 현재 운영 설정 수준의 통합은 완료되었지만, Queue driver별 장기 실행과 파일 롤백 및 전체 브라우저 조합은 별도 회귀 테스트가 필요하다.

## 11. 참고자료

- [Rhymix 공식 매뉴얼](https://rhymix.org/manual)
- [Rhymix 코딩 표준](https://rhymix.org/manual/contrib/coding-standards)
- [Rhymix 라우터 매뉴얼](https://rhymix.org/manual/plugin/router/router)
- [Rhymix 코어 저장소](https://github.com/rhymix/rhymix)
- [zodkr/rx-docs](https://github.com/zodkr/rx-docs)
- [fanbinit/rx-sticker](https://github.com/fanbinit/rx-sticker)
- [Waterticket/rx-module-sticker](https://github.com/Waterticket/rx-module-sticker)
