<?php

namespace App\Http\Requests\ManagerSection;

use Illuminate\Foundation\Http\FormRequest;
use Symfony\Component\HttpFoundation\Response;

class TsvFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'import' => [  // Changed from 'tsv_file' to match your code
                'required',
                'file',
                'mimes:tsv,txt',
                'max:10240',
                function ($attribute, $value, $fail) {
                    $stream = fopen($value->getPathname(), 'r');
                    $rowNumber = 0;

                    while (($row = fgetcsv($stream, 0, "\t")) !== false) {
                        if (empty(array_filter($row))) {
                            continue;
                        }

                        $rowNumber++;
                        if ($rowNumber > 6) {
                            $fail("TSV file exceeds maximum of 6 rows");
                            fclose($stream);
                            return;
                        }
                    }

                    fclose($stream);

                    if ($rowNumber === 0) {
                        $fail('The TSV file is empty');
                    }
                },
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'import.required' => 'Please upload a TSV file',
            'import.file' => 'The upload must be a file',
            'import.mimes' => 'The file must be a TSV or TXT file',
            'import.max' => 'The file size must not exceed 10MB',
        ];
    }
}
