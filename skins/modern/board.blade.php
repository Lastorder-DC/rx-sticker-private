@include('_setting')

<div class="stk">

@if(isset($view_grant))
	@if($view_grant === true)
		@if(!empty($sticker) && ($sticker->status !== 'STOP' || $grant->manager))
			@include('_read')
		@else
			<p class="stk-msg stk-msg--error">{{ $lang->stkr_msg_deleted_or_stopped }}</p>
		@endif
	@else
		<p class="stk-msg stk-msg--error">{{ $lang->stkr_msg_no_read_permission }}</p>
	@endif
@endif

@if($grant->list)
	@include('_list')
@else
	<p class="stk-msg stk-msg--error">{{ $lang->stkr_msg_no_access_permission }}</p>
@endif

</div>
