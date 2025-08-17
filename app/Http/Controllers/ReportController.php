<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use App\Models\DailyClosure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class ReportController extends Controller
{
    /**
     * Display sales summary report.
     */
    public function index(Request $request)
    {
        $startDate = $request->get('start_date', now()->startOfMonth()->toDateString());
        $endDate = $request->get('end_date', now()->toDateString());

        // Daily sales data for chart
        $dailySales = Sale::where('status', 'completed')
            ->whereBetween(DB::raw('DATE(created_at)'), [$startDate, $endDate])
            ->selectRaw('DATE(created_at) as date, SUM(total_amount) as total, COUNT(*) as count')
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        // Payment method breakdown
        $paymentMethods = Sale::where('status', 'completed')
            ->whereBetween(DB::raw('DATE(created_at)'), [$startDate, $endDate])
            ->selectRaw('payment_method, SUM(total_amount) as total, COUNT(*) as count')
            ->groupBy('payment_method')
            ->get();

        // Top selling products
        $topProducts = SaleItem::whereHas('sale', function ($query) use ($startDate, $endDate) {
                $query->where('status', 'completed')
                      ->whereBetween(DB::raw('DATE(created_at)'), [$startDate, $endDate]);
            })
            ->with('product.category')
            ->selectRaw('product_id, product_name, SUM(quantity) as total_sold, SUM(total_price) as revenue')
            ->groupBy('product_id', 'product_name')
            ->orderByDesc('total_sold')
            ->limit(10)
            ->get();

        // Summary totals
        $totalSales = Sale::where('status', 'completed')
            ->whereBetween(DB::raw('DATE(created_at)'), [$startDate, $endDate])
            ->sum('total_amount');

        $totalTransactions = Sale::where('status', 'completed')
            ->whereBetween(DB::raw('DATE(created_at)'), [$startDate, $endDate])
            ->count();

        $averageTransactionValue = $totalTransactions > 0 ? $totalSales / $totalTransactions : 0;

        return Inertia::render('reports/index', [
            'dailySales' => $dailySales,
            'paymentMethods' => $paymentMethods,
            'topProducts' => $topProducts,
            'summary' => [
                'totalSales' => $totalSales,
                'totalTransactions' => $totalTransactions,
                'averageTransactionValue' => $averageTransactionValue,
            ],
            'filters' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
        ]);
    }

    /**
     * Display sales by salesperson report.
     */
    public function show(Request $request)
    {
        $startDate = $request->get('start_date', now()->startOfMonth()->toDateString());
        $endDate = $request->get('end_date', now()->toDateString());

        $salesByUser = Sale::with('user')
            ->where('status', 'completed')
            ->whereBetween(DB::raw('DATE(created_at)'), [$startDate, $endDate])
            ->selectRaw('user_id, COUNT(*) as transaction_count, SUM(total_amount) as total_sales, AVG(total_amount) as avg_transaction')
            ->groupBy('user_id')
            ->orderByDesc('total_sales')
            ->get();

        return Inertia::render('reports/salesperson', [
            'salesByUser' => $salesByUser,
            'filters' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
        ]);
    }

    /**
     * Perform day closing procedure.
     */
    public function store(Request $request)
    {
        $today = now()->toDateString();
        
        // Check if day is already closed
        $existingClosure = DailyClosure::whereDate('closure_date', $today)->first();
        
        if ($existingClosure) {
            return back()->withErrors(['error' => 'Day has already been closed.']);
        }

        // Get today's sales data
        $todaySales = Sale::where('status', 'completed')->whereDate('created_at', now()->toDateString())->get();
        
        if ($todaySales->isEmpty()) {
            return back()->withErrors(['error' => 'No sales to close for today.']);
        }

        // Calculate totals by payment method
        $cashSales = $todaySales->where('payment_method', 'cash')->sum('total_amount');
        $cardSales = $todaySales->where('payment_method', 'card')->sum('total_amount');
        $qrCodeSales = $todaySales->where('payment_method', 'qr_code')->sum('total_amount');
        $totalSales = $todaySales->sum('total_amount');

        // Sales by user
        $salesByUser = $todaySales->groupBy('user_id')->map(function ($sales) {
            return [
                'user_name' => $sales->first()->user->name,
                'transaction_count' => $sales->count(),
                'total_sales' => $sales->sum('total_amount'),
            ];
        })->toArray();

        // Top products
        $topProducts = SaleItem::whereIn('sale_id', $todaySales->pluck('id'))
            ->selectRaw('product_name, SUM(quantity) as total_sold, SUM(total_price) as revenue')
            ->groupBy('product_name')
            ->orderByDesc('total_sold')
            ->limit(10)
            ->get()
            ->toArray();

        // Create closure record
        DailyClosure::create([
            'closure_date' => $today,
            'closed_by' => Auth::id(),
            'total_sales' => $totalSales,
            'cash_sales' => $cashSales,
            'card_sales' => $cardSales,
            'qr_code_sales' => $qrCodeSales,
            'transaction_count' => $todaySales->count(),
            'sales_by_user' => $salesByUser,
            'top_products' => $topProducts,
        ]);

        return redirect()->route('reports.closure-report', ['date' => $today])
            ->with('success', 'Day closed successfully.');
    }

    /**
     * Display day closure report.
     */
    public function edit($date)
    {
        $closure = DailyClosure::with('closedBy')
            ->whereDate('closure_date', $date)
            ->firstOrFail();

        return Inertia::render('reports/closure', [
            'closure' => $closure,
        ]);
    }
}