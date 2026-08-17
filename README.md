# 라이믹스 스티커 모듈

댓글에서 스티커(이모티콘)를 등록·판매·구매하고 사용할 수 있는 Rhymix 모듈입니다. 개인별 스티커 차단, WebP·MP4 업로드, 반응형 Blade 스킨, 선택형 GIF→MP4 변환을 지원합니다.

## 요구 사항

- PHP 7.4 이상
- Rhymix 2.1 이상
- GD 확장
- MP4 직접 업로드 시 FFmpeg 및 FFprobe
- GIF→MP4 기능 사용 시 FFmpeg
- 기존 GIF 일괄 변환 사용 시 Rhymix Queue 설정

## 설치 및 업데이트

1. 이 저장소를 `modules/sticker`에 배치합니다.
2. 관리자 화면에서 모듈 설치 또는 업데이트를 실행합니다.
3. `/sticker` 또는 `?mid=sticker`에서 목록·상세·등록 화면을 확인합니다.
4. 관리자 모듈 설정에서 페이지당 스티커 수(기본 12), 권한, 이미지 제한, 알림 및 GIF→MP4 옵션을 설정합니다.

기존 설치의 최초 모듈 업데이트 시 기본 선택은 반응형 `modern` Blade 스킨으로 전환됩니다. 이후 관리자 디자인 설정에서 `default`, `hios_sticker_skin`, `modern`을 다시 선택할 수 있으며, 이후 업데이트가 사용자 선택을 덮어쓰지 않습니다. 모바일은 PC와 동일한 반응형 스킨 또는 별도 모바일 스킨을 선택할 수 있습니다.

`modern` 스킨 설정에서는 상단 타이틀, 위젯 코드를 지원하는 제목 하단 설명, 샘플 가로·세로 크기, 제목 글자 수와 제작자 표시 여부를 설정할 수 있습니다. 다크모드에서는 제목과 태그 위젯을 레이아웃 색상에 맞추고, 상세 본문과 샘플은 원본 콘텐츠 색을 보존하도록 밝은 배경을 유지합니다. 검토 중인 스티커 상세 화면에는 관리자만 사용할 수 있는 공개 승인 버튼이 표시되며, IP 정보는 회원번호 4에게만 표시됩니다.

GIF→MP4 옵션의 초기값은 사용 안 함입니다. 운영 환경에서는 DB와 `files/`를 백업한 뒤 일부 GIF로 먼저 검증하고, 기존 GIF 변환은 작은 배치부터 실행하세요. 관리자 메뉴의 `GIF 변환 로그`에서 대기·처리 중·성공·건너뜀·실패 상태와 사유, 변환 전후 경로 및 용량을 확인하고 불완전한 작업을 개별 재시도할 수 있습니다.

직접 업로드한 MP4는 GIF→MP4 옵션이나 이미지 리사이징 설정과 관계없이 항상 FFprobe로 실제 영상 형식을 검증한 뒤 H.264 MP4로 재인코딩합니다. 모든 오디오·자막·데이터 스트림과 메타데이터를 제거하고, 프레임률을 30fps로 제한하며, 설정한 스티커 크기에 맞게 자른 후 WebP 포스터를 생성합니다.

DB에 경로만 남고 로컬 원본이 없는 파일은 `원본 파일 없음`으로 기록한 뒤 안전하게 건너뜁니다. 따라서 Cloudflare R2 등 외부 저장소에만 보관된 GIF는 자동으로 내려받거나 삭제하지 않으며, 이 일괄 변환의 대상은 로컬에서 읽을 수 있는 애니메이션 GIF입니다.

## 주요 구조

- `conf/module.xml`: 액션 권한, route, 이벤트 핸들러
- `controllers/EventHandlers.php`: 알림센터 연동
- `services/ImageProcessor.php`: 이미지·MP4 검증, 리사이즈 및 무음 영상 재인코딩
- `schemas/sticker_gif_conversion_log.xml`: 기존 GIF 변환의 파일별 최신 처리 상태
- `skins/modern`: 반응형 Blade 사용자 스킨
- `docs/waterticket-merge-analysis.md`: 저장소 비교, 통합 원칙 및 구현 현황

## 출처 및 라이선스

모듈은 기존 Huhani/fanbinit 계열 구현을 기반으로 하며, 일부 안정성 개선과 Blade 스킨 및 이미지 처리 구조는 [Waterticket/rx-module-sticker](https://github.com/Waterticket/rx-module-sticker)를 참고하여 현재 코드에 맞게 재구현했습니다.

`skins/modern`은 해당 디렉터리의 `LICENSE`(GPLv2)와 저작자 정보를 따릅니다. 번들 GIF 리사이저는 [coldume/imagecraft](https://github.com/coldume/imagecraft)입니다.
