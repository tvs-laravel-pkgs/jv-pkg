<?php

namespace Abs\JVPkg;
use Abs\ApprovalPkg\ApprovalFlowConfiguration;
use Abs\ApprovalPkg\ApprovalLevel;
use Abs\ApprovalPkg\EntityStatus;
use Abs\JVPkg\JournalVoucher;
use Abs\LocationPkg\State;
use App\ActivityLog;
use App\Attachment;
use App\Config;
use App\Entity;
use App\Http\Controllers\Controller;
use App\Outlet;
use Auth;
use Carbon\Carbon;
use DB;
use Illuminate\Http\Request;
use Yajra\Datatables\Datatables;

class JvVerificationController extends Controller {

	public function __construct() {
		$this->data['theme'] = config('custom.admin_theme');
	}

	public function getVerificationFilter(Request $request) {
		// dd($request->all());
		$this->data['approval_level'] = ApprovalLevel::find($request->level_id);
		// dd($approval_level);
		$this->data['extras'] = [
			'from_acc_list' => collect(Config::select('id', 'name')->where('config_type_id', 27)->get())->prepend(['id' => '', 'name' => 'Select From A/c Type']),
			'to_acc_list' => collect(Config::select('id', 'name')->where('config_type_id', 27)->get())->prepend(['id' => '', 'name' => 'Select To A/c Type']),
			'outlets' => collect(Outlet::select('id', 'code')->where('company_id', Auth::user()->company_id)->get())->prepend(['id' => '', 'code' => 'Select Outlet']),
			'states' => collect(State::select('id', 'name')->where('country_id', 1)->get())->prepend(['id' => '', 'name' => 'Select State']),
			'regions' => [],
			'jv_statuses' => collect(EntityStatus::select('id', 'name')->where('company_id', Auth::user()->company_id)->where('entity_id', 7221)->orderBy('id', 'asc')->get())->prepend(['id' => '', 'name' => 'Select JV Status']),
			'type_list' => collect(JVType::where('company_id', Auth::user()->company_id)->select('id', 'short_name')->get())->prepend(['id' => '', 'short_name' => 'Select JV Type']),
		];
		return response()->json($this->data);
	}

	public function getJvVerificationList(Request $request) {
		$approval_level = ApprovalLevel::where('id', $request->approval_level_id)
		// ->leftJoin('approval_type_approval_level as atal', 'atal.approval_level_id', 'approval_levels.id')
		// ->where('atal.approval_type_id', 2)
			->first();
		// dd($approval_level->current_status_id);
		// dd($request->all());
		if (!empty($request->jv_date)) {
			$jv_date = explode('to', $request->jv_date);
			$first_date_this_month = date('Y-m-d', strtotime($jv_date[0]));
			$last_date_this_month = date('Y-m-d', strtotime($jv_date[1]));
		} else {
			$first_date_this_month = '';
			$last_date_this_month = '';
		}
		$voucher_number_filter = $request->voucher_number;

		$jv_verification = JournalVoucher::withTrashed()
			->select([
				'journal_vouchers.*',
				'jv_types.short_name as jv_type',
				'from_account_types.name as from_account_type',
				'to_account_types.name as to_account_type',
				'es.name as jv_status',
				'outlets.code as outlet_code',
				'states.code as state_code',
				DB::raw('DATE_FORMAT(journal_vouchers.date,"%d-%m-%Y") as jv_date'),
				DB::raw('IF(regions.code IS NULL,"--",regions.code) as region_code'),
				DB::raw('CONCAT(users.ecode," / ",users.name) as created_by'),
				DB::raw('IF(journal_vouchers.deleted_at IS NULL, "Active","Inactive") as status'),
			])

			->leftJoin('jv_types', 'jv_types.id', 'journal_vouchers.type_id')
			->leftJoin('entity_statuses as es', 'es.id', 'journal_vouchers.status_id')
			->leftJoin('configs as from_account_types', 'from_account_types.id', 'journal_vouchers.from_account_type_id')
			->leftJoin('configs as to_account_types', 'to_account_types.id', 'journal_vouchers.to_account_type_id')
			->join('users', 'users.id', 'journal_vouchers.created_by_id')
			->join('employees', 'employees.id', 'users.entity_id')
			->join('outlets', 'outlets.id', 'employees.outlet_id')
			->leftJoin('regions', 'regions.id', 'outlets.region_id')
			->join('states', 'states.id', 'outlets.state_id')
		// ->where('users.user_type_id', 1) //FOR EMPLOYEE
		// ->where('journal_vouchers.company_id', Auth::user()->company_id)
			->where('journal_vouchers.status_id', $approval_level->current_status_id)
			->where(function ($query) use ($first_date_this_month, $last_date_this_month) {
				if (!empty($first_date_this_month) && !empty($last_date_this_month)) {
					$query->whereRaw("DATE(journal_vouchers.date) BETWEEN '" . $first_date_this_month . "' AND '" . $last_date_this_month . "'");
				}
			})
			->where(function ($query) use ($voucher_number_filter) {
				if ($voucher_number_filter != null) {
					$query->where('journal_vouchers.voucher_number', 'like', "%" . $voucher_number_filter . "%");
				}
			})
			->where(function ($query) use ($request) {
				if (!empty($request->type_id)) {
					$query->where('journal_vouchers.type_id', $request->type_id);
				}
			})
			->where(function ($query) use ($request) {
				if (!empty($request->outlet_id)) {
					$query->where('employees.outlet_id', $request->outlet_id);
				}
			})
			->where(function ($query) use ($request) {
				if (!empty($request->state_id)) {
					$query->where('outlets.state_id', $request->state_id);
				}
			})
			->where(function ($query) use ($request) {
				if (!empty($request->region_id)) {
					$query->where('outlets.region_id', $request->region_id);
				}
			})
			->where(function ($query) use ($request) {
				if (!empty($request->status_id)) {
					$query->where('journal_vouchers.status_id', $request->status_id);
				}
			})
			->where(function ($query) use ($request) {
				if (!empty($request->from_account_type_id)) {
					$query->where('journal_vouchers.from_account_type_id', $request->from_account_type_id);
				}
			})
			->where(function ($query) use ($request) {
				if (!empty($request->to_account_type_id)) {
					$query->where('journal_vouchers.to_account_type_id', $request->to_account_type_id);
				}
			})

			->orderby('journal_vouchers.id', 'desc')
		// ->get()
		;

		// if (Entrust::can('view-all-jv')) {
		// 	$jv_verification = $jv_verification->where('journal_vouchers.company_id', Auth::user()->company_id);
		// } elseif (Entrust::can('view-own-jv')) {
		// 	$jv_verification = $jv_verification->where('journal_vouchers.created_by_id', Auth::user()->id);
		// } else {
		// 	$jv_verification = [];
		// }

		// dd($jv_verification);
		return Datatables::of($jv_verification)
			->addColumn('child_checkbox', function ($jv_verification) {
				$checkbox = "<td><div class='table-checkbox'><input type='checkbox' id='child_" . $jv_verification->id . "' name='child_boxes' value='" . $jv_verification->id . "' class='jv_verfication_checkbox'/><label for='child_" . $jv_verification->id . "'></label></div></td>";

				return $checkbox;
			})
			->addColumn('number', function ($jv_verification) {
				$status = $jv_verification->status == 'Active' ? 'green' : 'red';
				// return '<span class="status-indicator ' . $status . '"></span>' . $jv_verification->voucher_number;
				return $jv_verification->voucher_number;
			})
			->addColumn('amount', function ($jv_verification) {
				$amount = '??? ' . $jv_verification->amount;
				return $amount;
			})
		// ->addColumn('from_ac_code', function ($jv_verification) {
		// 	if ($jv_verification->from_account_type_id == 1440) {
		// 		$from_ac_code = Customer::where('id', $jv_verification->from_account_id)->pluck('code')->first();
		// 	} elseif ($jv_verification->from_account_type_id == 1441) {
		// 		$from_ac_code = Vendor::where('id', $jv_verification->from_account_id)->pluck('code')->first();
		// 	} elseif ($jv_verification->from_account_type_id == 1442) {
		// 		$from_ac_code = Ledger::where('id', $jv_verification->from_account_id)->pluck('code')->first();
		// 	}
		// 	return $from_ac_code;
		// })
		// ->addColumn('to_ac_code', function ($jv_verification) {
		// 	if ($jv_verification->to_account_type_id == 1440) {
		// 		$to_ac_code = Customer::where('id', $jv_verification->to_account_id)->pluck('code')->first();
		// 	} elseif ($jv_verification->to_account_type_id == 1441) {
		// 		$to_ac_code = Vendor::where('id', $jv_verification->to_account_id)->pluck('code')->first();
		// 	} elseif ($jv_verification->to_account_type_id == 1442) {
		// 		$to_ac_code = Ledger::where('id', $jv_verification->from_account_id)->pluck('code')->first();
		// 	}
		// 	return $to_ac_code;
		// })
			->addColumn('action', function ($jv_verification) use ($request) {
				$img_view = asset('public/themes/' . $this->data['theme'] . '/img/content/table/eye.svg');
				$img_view_active = asset('public/themes/' . $this->data['theme'] . '/img/content/table/eye-active.svg');
				$img_delete = asset('public/themes/' . $this->data['theme'] . '/img/content/table/delete-default.svg');
				$img_delete_active = asset('public/themes/' . $this->data['theme'] . '/img/content/table/delete-active.svg');
				$output = '';
				$output .= '<a href="#!/verification/7221/level/' . $request->approval_level_id . '/view/' . $jv_verification->id . '" id = "" title="View"><img src="' . $img_view . '" alt="View" class="img-responsive" onmouseover=this.src="' . $img_view_active . '" onmouseout=this.src="' . $img_view . '"></a>';

				// $output .= '<a href="javascript:;" data-toggle="modal" data-target="#journal-voucher-delete-modal" onclick="angular.element(this).scope().deleteJournalVoucher(' . $journal_vouchers->id . ')" title="Delete"><img src="' . $img_delete . '" alt="Delete" class="img-responsive delete" onmouseover=this.src="' . $img_delete_active . '" onmouseout=this.src="' . $img_delete . '"></a>';

				return $output;
			})
			->rawColumns(['child_checkbox', 'action', 'amount'])
			->make(true);
	}

	public function viewJvVerification(Request $request) {
		$this->data = JournalVoucher::getJvViewData($request);
		$this->data['approval_level'] = ApprovalLevel::find($request->approval_level_id);
		$this->data['rejection_reasons'] = Entity::where('entity_type_id', 21)->get();
		return response()->json($this->data);
	}

	public function jvAttachmentViewedCheck(Request $request) {
		// dd($request->all());
		$journal_voucher_id = Attachment::where('id', $request->attachment_id)->pluck('entity_id')->first();
		$attachment_jv_user = DB::table('jv_attachment_view_status')
			->updateOrInsert(['attachment_id' => $request->attachment_id, 'journal_voucher_id' => $journal_voucher_id], ['viewed_by' => Auth::user()->id]);
		return response()->json(['success' => true]);
	}

	public function saveJvVerification(Request $request) {
		// dd($request->all());
		try {
			DB::beginTransaction();

			$jv_amount_get = JournalVoucher::find($request->journal_voucher_id);
			// dd($jv_amount_get->amount);
			$approval_level = ApprovalLevel::where('id', $request->approval_level_id)
			// ->leftJoin('approval_type_approval_level as atal', 'atal.approval_level_id', 'approval_levels.id')
			// ->where('atal.approval_type_id', 2)
				->first();

			if ($approval_level->has_verification_flow == 1) {
				// dd('in');
				$verification_flow_configuration = ApprovalFlowConfiguration::where('approval_level_id', $approval_level->id)
					->where('value', ">=", $jv_amount_get->amount)
					->orderBy('value')
					->first()
				;
				if (!$verification_flow_configuration) {
					return response()->json([
						'success' => false,
						'errors' => ['Approval Flow Not Configured!. Check the Verification Flow Configuration Master!.'],
					]);
				}
				$status_id = $verification_flow_configuration->next_status_id;
			} else {
				$status_id = $approval_level->next_status_id;
			}
			// dump($status_id);
			// dd($approval_level);

			// dd($approval_level->reject_status_id);

			if ($request->verification_type == 'approve') {
				//CHECK IF ATTACHMENT VIEWED OR NOT
				$attachments = Attachment::where('entity_id', $request->journal_voucher_id)->where('attachment_of_id', 223)->where('attachment_type_id', 244)->pluck('id')->toArray();
				// dd($attachments);
				foreach ($attachments as $key => $attachment_id) {
					$viewed_jv_attachment = DB::table('jv_attachment_view_status')->where('attachment_id', $attachment_id)->where('journal_voucher_id', $request->journal_voucher_id)->where('viewed_by', Auth::user()->id)->first();
					if (!$viewed_jv_attachment) {
						return response()->json([
							'success' => false,
							'errors' => ['Please check whether all Journal Vouchers copies are viewed'],
						]);
					}
				}

				$jv = JournalVoucher::find($request->journal_voucher_id);
				$jv->status_id = $status_id;
				$jv->rejection_id = NULL;
				$jv->rejection_reason = NULL;
				$jv->save();
				if ($jv) {
					$status_id = $status_id;
					$activity = new ActivityLog;
					$activity->date_time = Carbon::now();
					$activity->user_id = Auth::user()->id;
					$activity->module = 'JV Verification';
					$activity->entity_id = $request->journal_voucher_id;
					$activity->entity_type_id = 384;
					$activity->activity_id = 7221;
					$activity->activity = 7221;
					$activity->details = json_encode($jv);
					$activity->save();
					DB::commit();
					return response()->json([
						'success' => true,
						'message' => 'Approved Successfully',
					]);
				} else {
					return response()->json([
						'success' => false,
						'errors' => ['Approval Error'],
					]);
				}
			} elseif ($request->verification_type == 'Reject') {
				$jv = JournalVoucher::find($request->journal_voucher_id);
				$jv->status_id = $approval_level->reject_status_id;
				$jv->rejection_id = $request->reject_reason_id;
				$jv->rejection_reason = $request->rejection_reason;
				$jv->save();
				if ($jv) {
					$status_id = $approval_level->reject_status_id;
					$activity = new ActivityLog;
					$activity->date_time = Carbon::now();
					$activity->user_id = Auth::user()->id;
					$activity->module = 'JV Verification';
					$activity->entity_id = $request->journal_voucher_id;
					$activity->entity_type_id = 384;
					$activity->activity_id = 7221;
					$activity->activity = 7221;
					$activity->details = json_encode($jv);
					$activity->save();
					DB::commit();
					return response()->json([
						'success' => true,
						'message' => 'Rejected Successfully',
					]);
				} else {
					return response()->json([
						'success' => false,
						'errors' => ['Rejection Error'],
					]);
				}

			}
		} catch (Exceprion $e) {
			DB::rollBack();
			return response()->json([
				'success' => false,
				'errors' => $e->getMessage(),
			]);
		}
	}

	public function jvMultipleApproval(Request $request) {
		// dd($request->all());
		$send_for_approvals = JournalVoucher::whereIn('id', $request->send_for_approval)->pluck('id')->toArray();
		// dd($send_for_approvals);
		$approval_level = ApprovalLevel::where('id', $request->approval_level_id)
		// ->leftJoin('approval_type_approval_level as atal', 'atal.approval_level_id', 'approval_levels.id')
		// ->where('atal.approval_type_id', 2)
			->first();
		// dd($approval_level);
		// dd($approval_level->next_status_id);
		// if (count($send_for_approvals) == 0) {
		// 	return response()->json(['success' => false, 'errors' => ['No Approval 1 Pending Status in the list!']]);
		// } else {

		if ($approval_level->has_verification_flow == 1) {
			// dd('in');
			$verification_flow_configuration = ApprovalFlowConfiguration::where('approval_level_id', $approval_level->id)
				->where('value', ">=", $jv_amount_get->amount)
				->orderBy('value')
				->first()
			;
			if (!$verification_flow_configuration) {
				return response()->json([
					'success' => false,
					'errors' => ['Approval Flow Not Configured!. Check the Verification Flow Configuration Master!.'],
				]);
			}
			$status_id = $verification_flow_configuration->next_status_id;
		} else {
			$status_id = $approval_level->next_status_id;
		}
		DB::beginTransaction();
		try {
			foreach ($send_for_approvals as $key => $value) {
				// dump($value);
				//CHECK IF ATTACHMENT VIEWED OR NOT
				$attachments = Attachment::where('entity_id', $value)->where('attachment_of_id', 223)->where('attachment_type_id', 244)->pluck('id')->toArray();
				foreach ($attachments as $key => $attachment_id) {
					$viewed_jv_attachment = DB::table('jv_attachment_view_status')->where('attachment_id', $attachment_id)->where('journal_voucher_id', $value)->where('viewed_by', Auth::user()->id)->first();
					if (!$viewed_jv_attachment) {
						return response()->json([
							'success' => false,
							'errors' => ['Please check whether all Journal Vouchers copies are viewed'],
						]);
					}
				}

				$journal_voucher = JournalVoucher::find($value);
				// $journal_voucher->status_id = $approval_level->next_status_id;
				$journal_voucher->status_id = $status_id;
				$journal_voucher->rejection_id = NULL;
				$journal_voucher->rejection_reason = NULL;
				$journal_voucher->updated_by_id = Auth()->user()->id;
				$journal_voucher->updated_at = date("Y-m-d H:i:s");
				$journal_voucher->save();

				// $status_id = $approval_level->next_status_id;
				$status_id = $status_id;
				$activity = new ActivityLog;
				$activity->date_time = Carbon::now();
				$activity->user_id = Auth::user()->id;
				$activity->module = 'JV Verification';
				$activity->entity_id = $value;
				$activity->entity_type_id = 384;
				$activity->activity_id = 7221;
				$activity->activity = 7221;
				$activity->details = json_encode($journal_voucher);
				$activity->save();
			}
			DB::commit();
			return response()->json([
				'success' => true,
				'message' => $approval_level->name . ' Approved successfully',
			]);
		} catch (Exception $e) {
			DB::rollBack();
			return response()->json([
				'success' => false,
				'errors' => ['Exception Error' => $e->getMessage()],
			]);
		}
		// }
	}
}
