<?php

namespace App\Http\Controllers\Api\V1;

use App\Company;
use App\CompanyAccount;
use App\Contract;
use App\Employee;
use App\EmployeePayroll;
use App\EmployerNumber;
use App\Helpers\Util;
use App\Http\Controllers\Controller;
use App\ManagementEntity;
use App\Month;
use App\Payroll;
use App\PositionGroup;
use App\Procedure;
use App\TotalPayrollEmployee;
use App\TotalPayrollEmployer;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Response;
use App\Exports\PayrollsExport;
use Maatwebsite\Excel\Facades\Excel;

class PayrollPrintController extends Controller
{
  private function getFormattedData($year, $month, $valid_contracts, $with_account, $management_entity, $position_group, $employer_number)
  {
    $procedure = Procedure::where('month_id', $month)->where('year', $year)->whereNull('deleted_at')->first();

    if (isset($procedure->id)) {
      $previous_date = Carbon::create($procedure->year, $procedure->month->order)->subMonths(1);
      $previous_procedure = Procedure::where('month_id', $previous_date->month)->where('year', $previous_date->year)->whereNull('deleted_at')->first();

      $employees = array();
      $total_discounts = new TotalPayrollEmployee();
      $total_contributions = new TotalPayrollEmployer();
      $company = Company::select()->first();

      //$payrolls = Payroll::where('procedure_id', $procedure->id)->leftjoin('contracts as c', 'c.id', '=', 'payrolls.contract_id')->leftjoin('employees as e', 'e.id', '=', 'c.employee_id')->orderBy('e.last_name')->orderBy('e.mothers_last_name')->orderBy('c.start_date')->select('payrolls.*')->get();
      $payrolls = Payroll::where('procedure_id', $procedure->id)->leftjoin('contracts as c', 'c.id', '=', 'payrolls.contract_id')->leftjoin('employees as e', 'e.id', '=', 'c.employee_id')->orderBy('e.last_name')->orderby('c.employee_id')->select('payrolls.*')->get();

      foreach ($payrolls as $key => $payroll) {
        $contract = $payroll->contract;
        $employee = $contract->employee;

        $rehired = true;
        $employee_contracts = $employee->contracts;

        $e = new EmployeePayroll($payroll);

        if (count($employee_contracts) > 1) {
          $rehired = Util::valid_contract($payroll, $employee->last_contract());

          if ($rehired) {
            $e->setValidContact(true);
          }
        }

        if (($valid_contracts && !$e->valid_contract) || (($management_entity != 0) && ($e->management_entity_id != $management_entity)) || (($position_group != 0) && ($e->position_group_id != $position_group)) || ($employer_number && ($e->employer_number_id != $employer_number)) || ($with_account && !$employee->account_number)) {
          $e->setZeroAccounts();
        } else {
          $employees[] = $e;
        }

        $total_discounts->add_base_wage($e->base_wage);
        $total_discounts->add_quotable($e->quotable);
        $total_discounts->add_discount_old($e->discount_old);
        $total_discounts->add_discount_common_risk($e->discount_common_risk);
        $total_discounts->add_discount_commission($e->discount_commission);
        $total_discounts->add_discount_solidary($e->discount_solidary);
        $total_discounts->add_discount_national($e->discount_national);
        $total_discounts->add_total_amount_discount_law($e->total_amount_discount_law);
        $total_discounts->add_net_salary($e->net_salary);
        $total_discounts->add_discount_rc_iva($e->discount_rc_iva);
        $total_discounts->add_total_faults($e->discount_faults);
        $total_discounts->add_total_discounts($e->total_discounts);
        $total_discounts->add_payable_liquid($e->payable_liquid);

        $total_contributions->add_quotable($e->quotable);
        $total_contributions->add_contribution_insurance_company($e->contribution_insurance_company);
        $total_contributions->add_contribution_professional_risk($e->contribution_professional_risk);
        $total_contributions->add_contribution_employer_solidary($e->contribution_employer_solidary);
        $total_contributions->add_contribution_employer_housing($e->contribution_employer_housing);
        $total_contributions->add_total_contributions($e->total_contributions);
      }
    } else {
      abort(404);
    }

    return (object)array(
      "data" => [
        'total_discounts' => $total_discounts,
        'total_contributions' => $total_contributions,
        'employees' => $employees,
        'procedure' => $procedure,
        'previous_procedure' => $previous_procedure ? $previous_procedure : $procedure,
        'minimum_salary' => $procedure->minimum_salary,
        'company' => $company,
        'title' => (object)array(
          'year' => $year,
        ),
      ],
    );
  }

  /**
   * Print PDF payroll reports.
   *
   * @param  integer  $year
   * @param  integer  $month
   * @param  string  $report_type
   * @param  string  $report_name
   * @param  boolean $valid_contracts
   * @param  integer  $management_entity_id
   * @param  integer  $position_group_id
   * @param  integer  $employer_number_id
   * @return \PDF
   */
  public function print_pdf(Request $params, $year, $month)
  {
    $month = Month::where('id', $month)->select()->first();
    if (!$month) {
      abort(404);
    }

    $params = $params->all();

    $employer_number = 0;
    $position_group = 0;
    $management_entity = 0;
    $with_account = 0;
    $valid_contracts = 0;
    $report_name = '';
    $report_type = 'H';

    switch (count($params)) {
      case 7:
        $employer_number = request('employer_number');
      case 6:
        $position_group = request('position_group');
      case 5:
        $management_entity = request('management_entity');
      case 4:
        $with_account = request('with_account');
      case 3:
        $valid_contracts = request('valid_contracts');
      case 2:
        $report_name = request('report_name');
      case 1:
        $report_type = mb_strtoupper(request('report_type'));
        break;
      default:
        abort(404);
    }

    $response = $this->getFormattedData($year, $month->id, $valid_contracts, $with_account, $management_entity, $position_group, $employer_number);

    $response->data['title']->subtitle = '';
    $response->data['title']->management_entity = '';
    $response->data['title']->position_group = '';
    $response->data['title']->employer_number = '';
    $response->data['title']->report_name = $report_name;
    $response->data['title']->report_type = $report_type;
    $response->data['title']->month = $month->name;

    switch ($report_type) {
      case 'H':
        $response->data['title']->name = 'PLANILLA DE HABERES';
        $response->data['title']->table_header = 'DESCUENTOS DEL SISTEMA DE PENSIONES';
        break;
      case 'P':
        $response->data['title']->name = 'PLANILLA PATRONAL';
        $response->data['title']->table_header = 'APORTES PATRONALES';
        break;
      case 'T':
        $response->data['title']->name = 'PLANILLA TRIBUTARIA';
        $response->data['title']->table_header = 'S.M.N.';
        $response->data['title']->table_header2 = $response->data['minimum_salary']->value;
        $response->data['title']->table_header3 = 'Saldo a favor de:';
        $response->data['title']->table_header4 = 'Saldo anterior a favor del dependiente';
        $response->data['title']->minimun_salary = $response->data['minimum_salary']->value;
        $response->data['title']->ufv = $response->data['procedure']->ufv;
        $response->data['title']->previous_ufv = $response->data['previous_procedure']->ufv;
        break;
      case 'S':
        $response->data['title']->name = 'PLANILLA FONDO SOLIDARIO';
        $response->data['title']->table_header = 'TOTAL GANADO SOLIDARIO';
        $limits = $response->data['procedure']->employee_discount->national_limits;
        if (count($limits) > 0) {
          $response->data['employees'] = array_filter($response->data['employees'], function ($e) use ($limits, $response) {
            if ($e->quotable > $limits[0]) {
              return true;
            }
            return false;
          });
        }
        break;
      default:
        abort(404);
    }

    if ($management_entity) {
      $response->data['title']->management_entity = ManagementEntity::find($management_entity)->name;
    }
    if ($position_group) {
      $position_group = PositionGroup::find($position_group);
      $response->data['title']->position_group = $position_group->name;
      $response->data['company']->employer_number = $position_group->employer_number->number;
    }
    if ($employer_number) {
      $employer_number = EmployerNumber::find($employer_number);
      $response->data['title']->employer_number = $employer_number->number;
      $response->data['company']->employer_number = $employer_number->number;
    }

    $file_name = implode(" ", [$response->data['title']->name, $report_name, $year, mb_strtoupper($month->name)]) . ".pdf";

    $footerHtml = view()->make('partials.footer')->with(array('paginator' => true, 'print_message' => $response->data['procedure']->active ? 'Borrador' : null, 'print_date' => $response->data['procedure']->active, 'date' => null))->render();

    $options = [
      'orientation' => 'landscape',
      'page-width' => '216',
      'page-height' => '330',
      'margin-top' => '5',
      'margin-right' => '10',
      'margin-left' => '10',
      'margin-bottom' => '15',
      'encoding' => 'UTF-8',
      'footer-html' => $footerHtml,
      'user-style-sheet' => public_path('css/payroll-print.min.css')
    ];

    $pdf = \PDF::loadView('payroll.print', $response->data);
    $pdf->setOptions($options);

    return $pdf->stream($file_name);
  }

  /**
   * Print TXT payroll reports.
   *
   * @param  integer  $year
   * @param  integer  $month
   * @return \TXT
   */
  public function print_txt($year, $month)
  {
    $month = Month::findorFail($month);

    $response = $this->getFormattedData($year, $month->order, 1, 1, 0, 0, 0, 0);
    $total_employees = count($response->data['employees']);

    if ($total_employees == 0) {
      abort(404);
    }

    $content = "";

    $content .= "sueldo del mes de " . strtolower($month->name) . " " . $year . " " . Util::fillZerosLeft(strval($total_employees), 4) . Carbon::now()->format('dmY') . "\r\n";

    $content .= CompanyAccount::where('active', true)->first()->account . Util::fillZerosLeft(strval(Util::format_number($response->data['total_discounts']->payable_liquid, 2, '', '.')), 12) . "\r\n";

    foreach ($response->data['employees'] as $i => $employee) {
      if ($employee->account_number) {
        $content .= $employee->account_number . Util::fillZerosLeft(strval(Util::format_number($employee->payable_liquid, 2, '', '.')), 12) . "1";
        if ($i < ($total_employees - 1)) {
          $content .= "\r\n";
        }
      }
    }

    $filename = implode('_', ["sueldos", strtolower($month->name), $year]) . ".txt";

    $headers = [
      'Content-type' => 'text/plain',
      'Content-Disposition' => sprintf('attachment; filename="%s"', $filename)
    ];

    return Response::make($content, 200, $headers);
  }

  public function print_ovt($year, $month)
  {
    $month = Month::where('id', $month)->select()->first();
    if (!$month) {
      abort(404);
    }

    $employees = $this->getFormattedData($year, $month->id, 0, 0, 0, 0, 0, 0)->data['employees'];
    $grouped_payrolls = [];

    foreach ($employees as $e) {
      $grouped_payrolls[$e->code][] = $e;
    }

    $employees = [];
    foreach ($grouped_payrolls as $payroll_group) {
      $p = null;
      foreach ($payroll_group as $key => $pr) {
        if ($key == 0) {
          $p = $pr;
        } else {
          $p->mergePayroll($pr);
        }
      }
      $employees[] = $p;
    }

    $total_employees = count($employees);

    $content = "";

    $content .= implode(',', ["Nro", "Tipo de documento de identidad", "Número de documento de identidad", "Lugar de expedición", "Fecha de nacimiento", "Primer Apellido", "Segundo Apellido", "Nombres", "País de nacionalidad", "Sexo", "Jubilado", "¿Aporta a la AFP?", "¿Persona con discapacidad?", "Tutor de persona con discapacidad", "Fecha de ingreso", "Fecha de retiro", "Motivo retiro", "Caja de salud", "AFP a la que aporta", "NUA/CUA", "Sucursal o ubicación adicional", "Clasificación laboral", "Cargo", "Modalidad de contrato", "Tipo contrato", "Días pagados", "Horas pagadas", "Haber Básico", "Bono de antigüedad", "Horas extra", "Monto horas extra", "Horas recargo nocturno", "Monto horas extra nocturnas", "Horas extra dominicales", "Monto horas extra dominicales", "Domingos trabajados", "Monto domingo trabajado", "Nro. dominicales", "Salario dominical", "Bono producción", "Subsidio frontera", "Otros bonos y pagos", "RC-IVA", "Aporte Caja Salud", "Aporte AFP", "Otros descuentos", "\r\n"]);

    foreach ($employees as $i => $e) {
      $name = (is_null($e->second_name)) ? $e->first_name : implode(' ', [$e->first_name, $e->second_name]);
      $content .= implode(',', [++$i, "CI", $e->ci, $e->id_ext, $e->birth_date, $e->last_name, $e->mothers_last_name, $name, "BOLIVIA", $e->gender, "0", "1", "0", "0", $e->start_date, "", "", $e->ovt->insurance_company_id, $e->ovt->management_entity_id, $e->nua_cua, "1", "", mb_strtoupper(str_replace(",", " ", $e->position)), $e->ovt->contract_type, $e->ovt->contract_mode, $e->worked_days, "8", round($e->quotable, 2), "0", "", "", "", "", "", "", "", "", "", "", "", "", "", "", round($e->discount_old, 2), round($e->total_amount_discount_law, 2), round($e->discount_faults, 2)]);

      if ($i < ($total_employees)) {
        $content .= "\r\n";
      }
    }

    $filename = implode('_', ["planilla", "ovt", strtolower($month->name), $year]) . ".csv";

    $headers = [
      'Content-type' => 'text/csv',
      'Content-Disposition' => sprintf('attachment; filename="%s"', $filename)
    ];

    return Response::make($content, 200, $headers);
  }

  public function print_afp($management_entity_id, $year, $month)
  {
    $management_entity = ManagementEntity::findOrFail($management_entity_id);
    $month = Month::findOrFail($month);

    $employees = $this->getFormattedData($year, $month->id, 0, 0, $management_entity->id, 0, 0, 0)->data['employees'];

    $grouped_payrolls = [];

    foreach ($employees as $e) {
      $grouped_payrolls[$e->employee_id][] = $e;
    }

    $employees = [];
    foreach ($grouped_payrolls as $payroll_group) {
      $p = null;
      foreach ($payroll_group as $key => $pr) {
        if ($key == 0) {
          $p = $pr;
        } else {
          $p->mergePayroll($pr);
        }
      }
      $employees[] = $p;
    }

    similar_text(strtolower($management_entity->name), 'prevision', $prevision_similarity);
    similar_text(strtolower($management_entity->name), 'futuro', $futuro_similarity);

    if ($prevision_similarity > $futuro_similarity) {
      $data = [];

      foreach ($employees as $employee) {
        $e = Employee::find($employee->employee_id);

        $ci = explode('-', $employee->ci);

        $first_contract = $e->first_contract();
        $first_date = Carbon::parse($first_contract->start_date);

        $last_contract = $e->last_contract();

        if ($last_contract->retirement_date) {
          $retirement_date = Carbon::parse($last_contract->retirement_date);
        } else {
          $retirement_date = $last_contract->retirement_date;
        }

        if ($retirement_date) {
          if ($retirement_date->year == $year && $retirement_date->month == $month->order) {
            $update = 'R';
            $update_date = $retirement_date->format('Ymd');
          }
        } elseif ($first_date->year == $year && $first_date->month == $month->order) {
          $update = 'I';
          $update_date = $first_date->format('Ymd');
        } else {
          $update = '';
          $update_date = '';
        }

        $data[] = [
          'doc_type' => 'CI',
          'ci' => $ci[0],
          'ci_complement' => (count($ci) > 1) ? $ci[1] : '',
          'nua_cua' => $employee->nua_cua,
          'last_name' => $employee->last_name,
          'mothers_last_name' => $employee->mothers_last_name,
          'husband_last_name' => '',
          'first_name' => $employee->first_name,
          'second_name' => $employee->second_name,
          'update' => $update,
          'update_date' => $update_date,
          'worked_days' => $employee->worked_days,
          'quotable' => round($employee->quotable, 2),
          'contributor' => '1',
          'insurance_type' => ''
        ];
      }

      $headers = [
        'TIPO DOC.',
        'NUMERO DOCUMENTO',
        'ALFANUMERICO DEL DOCUMENTO',
        'NUA/CUA',
        'AP. PATERNO',
        'AP. MATERNO',
        'AP. CASADA',
        'PRIMER NOMBRE',
        'SEG. NOMBRE',
        'NOVEDAD',
        'FECHA NOVEDAD',
        'DIAS',
        'TOTAL GANADO',
        'TIPO COTIZANTE',
        'TIPO ASEGURADO',
      ];

      $filename = implode('_', ["planilla", strtolower($management_entity->name), strtolower($month->name), $year]) . ".xlsx";

      return Excel::download(new PayrollsExport($data, $headers), $filename);
    } else {
      $content = "";

      $total_employees = count($employees);

      foreach ($employees as $i => $employee) {
        $e = Employee::find($employee->employee_id);

        $first_contract = $e->first_contract();
        $first_date = Carbon::parse($first_contract->start_date);

        $last_contract = $e->last_contract();

        $address = $last_contract->position->position_group->company_address->city->name;

        if ($last_contract->retirement_date) {
          $retirement_date = Carbon::parse($last_contract->retirement_date);
        } else {
          $retirement_date = $last_contract->retirement_date;
        }

        if ($retirement_date) {
          if ($retirement_date->year == $year && $retirement_date->month == $month->order) {
            $update = 'R';
            $update_date = $retirement_date->format('d/m/Y');
          }
        } elseif ($first_date->year == $year && $first_date->month == $month->order) {
          $update = 'I';
          $update_date = $first_date->format('d/m/Y');
        } else {
          $update = '';
          $update_date = '';
        }

        $content .= implode(',', [++$i, 'CI', $employee->ci, $employee->id_ext, $employee->nua_cua, $employee->last_name, $employee->mothers_last_name, null, $employee->first_name, $employee->second_name, $address, $update, $update_date, $employee->worked_days, 'E', round($employee->quotable, 2), null, null, null, null, null, null, null]);

        if ($i < ($total_employees)) {
          $content .= "\r\n";
        }
      }

      $filename = implode('_', ["planilla", strtolower($management_entity->name), strtolower($month->name), $year]) . ".csv";

      $headers = [
        'Content-type' => 'text/csv',
        'Content-Disposition' => sprintf('attachment; filename="%s"', $filename)
      ];

      return Response::make($content, 200, $headers);
    }
  }

  public function certificate($employee_id)
  {
    $employees = array();
    $total_discounts = new TotalPayrollEmployee();
    $total_contributions = new TotalPayrollEmployer();
    $company = Company::select()->first();
    $payrolls = Payroll::where('employee_id', $employee_id)
      ->join('procedures as p', 'p.id', '=', 'payrolls.procedure_id')
      ->join('months as m', 'm.id', '=', 'p.month_id')
      ->orderBy('p.year')
      ->orderBy('m.order')
      ->get();
    foreach ($payrolls as $key => $payroll) {
      $contract = $payroll->contract;
      $employee = $contract->employee;

      $rehired = true;
      $employee_contracts = $payroll->contract->employee->contracts;

      $e = new EmployeePayroll($payroll);

      if (count($employee_contracts) > 1) {
        $rehired = Util::valid_contract($payroll, $employee->last_contract());

        if ($rehired) {
          $e->setValidContact(true);
        }
      }
      $employees[] = $e;
    }
    return $employees;
  }

  public function print_certificate($employee_id)
  {
    $data['payrolls'] = $this->certificate($employee_id);
    $data['contract'] = Contract::with('employee', 'employee.city_identity_card', 'position')->where('employee_id', $employee_id)->orderBy('end_date', 'desc')->select('contracts.active as act', '*')->first();

    return \PDF::loadView('payroll.print_certificate', $data)
      ->setOption('page-width', '220')
      ->setOption('page-height', '280')
      ->setOption('margin-left', '20')
      ->setOption('margin-right', '15')
      ->setOption('encoding', 'utf-8')
      ->stream('certificado');
  }
}
